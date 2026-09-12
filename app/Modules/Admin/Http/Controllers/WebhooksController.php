<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\StoreWebhookEndpointRequest;
use App\Modules\Admin\Http\Requests\UpdateWebhookEndpointRequest;
use App\Modules\Webhooks\Jobs\DeliverWebhookJob;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Webhooks: Endpunkte anlegen, bearbeiten, deaktivieren (Secret nur einmal sichtbar), Delivery-Log,
 * erneute Zustellung und DLQ-Ansicht (Status dead). Alle Aktionen erfordern webhooks.manage.
 */
final class WebhooksController extends AdminController
{
    private const string SESSION_SECRET = 'admin.webhooks.secret';

    public function __construct(private readonly Dispatcher $bus) {}

    public function index(Request $request): View
    {
        $this->requirePermission('webhooks.manage');

        $endpoints = WebhookEndpoint::query()
            ->withCount([
                'deliveries as delivered_count' => static fn (Builder $q) => $q->where('status', WebhookDelivery::STATUS_DELIVERED),
                'deliveries as dead_count' => static fn (Builder $q) => $q->where('status', WebhookDelivery::STATUS_DEAD),
            ])
            ->orderBy('name')
            ->paginate($this->perPage())
            ->withQueryString();

        $revealed = $request->session()->pull(self::SESSION_SECRET);

        return view('admin::webhooks.index', [
            'endpoints' => $endpoints,
            'enabled' => (bool) config('hub.webhooks.enabled', false),
            'events' => (array) config('hub.webhooks.events', []),
            'revealed' => is_array($revealed) ? $revealed : null,
            'deadTotal' => $this->deliveryQuery()->where('webhook_deliveries.status', WebhookDelivery::STATUS_DEAD)->count(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->requirePermission('webhooks.manage');

        return view('admin::webhooks.create', ['events' => (array) config('hub.webhooks.events', [])]);
    }

    public function store(StoreWebhookEndpointRequest $request): RedirectResponse
    {
        $this->requirePermission('webhooks.manage');
        $data = $request->validated();
        $user = $this->currentUser($request);

        if (WebhookEndpoint::query()->where('name', $data['name'])->exists()) {
            return redirect()->route('admin.webhooks.create')->withErrors(['name' => 'Ein Endpunkt mit diesem Namen existiert bereits.'])->withInput();
        }

        $secret = 'whsec_'.Str::random(40);

        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill([
            'organization_id' => (int) $user->getAttribute('organization_id'),
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $secret,
            'events' => array_values(array_unique($data['events'])),
            'active' => (bool) ($data['active'] ?? true),
            'created_by' => $user->getKey(),
        ]);
        $endpoint->save();

        $this->audit('webhooks.endpoint_created', $endpoint, [], ['name' => $endpoint->name, 'url' => $endpoint->url, 'events' => $endpoint->events, 'active' => (bool) $endpoint->active]);

        // Klartext-Secret genau einmal über die Session an die nächste Seite reichen, nie speichern.
        $request->session()->flash(self::SESSION_SECRET, ['endpoint_id' => (int) $endpoint->getKey(), 'name' => $endpoint->name, 'secret' => $secret]);

        return $this->redirectWithStatus('admin.webhooks.index', 'Endpunkt angelegt. Das Secret wird nur dieses eine Mal angezeigt.');
    }

    public function edit(Request $request, int $endpoint): View
    {
        $this->requirePermission('webhooks.manage');
        $endpoint = $this->findEndpoint($endpoint);

        return view('admin::webhooks.edit', ['endpoint' => $endpoint, 'events' => (array) config('hub.webhooks.events', [])]);
    }

    public function update(UpdateWebhookEndpointRequest $request, int $endpoint): RedirectResponse
    {
        $this->requirePermission('webhooks.manage');
        $endpoint = $this->findEndpoint($endpoint);
        $data = $request->validated();

        if (WebhookEndpoint::query()->where('name', $data['name'])->whereKeyNot($endpoint->getKey())->exists()) {
            return redirect()->route('admin.webhooks.edit', $endpoint)->withErrors(['name' => 'Ein Endpunkt mit diesem Namen existiert bereits.'])->withInput();
        }

        $before = ['name' => $endpoint->name, 'url' => $endpoint->url, 'events' => $endpoint->events];
        $endpoint->forceFill(['name' => $data['name'], 'url' => $data['url'], 'events' => array_values(array_unique($data['events']))])->save();

        $this->audit('webhooks.endpoint_updated', $endpoint, $before, ['name' => $endpoint->name, 'url' => $endpoint->url, 'events' => $endpoint->events]);

        return $this->redirectWithStatus('admin.webhooks.index', 'Endpunkt aktualisiert.');
    }

    public function deactivate(Request $request, int $endpoint): RedirectResponse
    {
        $this->requirePermission('webhooks.manage');
        $this->requireConfirmation($request);
        $endpoint = $this->findEndpoint($endpoint);

        if ((bool) $endpoint->active) {
            $endpoint->forceFill(['active' => false])->save();
            $this->audit('webhooks.endpoint_deactivated', $endpoint, ['active' => true], ['active' => false, 'reason' => (string) $request->input('reason', '')]);
        }

        return $this->redirectWithStatus('admin.webhooks.index', 'Endpunkt deaktiviert. Offene Zustellungen werden übersprungen.');
    }

    public function activate(Request $request, int $endpoint): RedirectResponse
    {
        $this->requirePermission('webhooks.manage');
        $endpoint = $this->findEndpoint($endpoint);

        if (! (bool) $endpoint->active) {
            $endpoint->forceFill(['active' => true])->save();
            $this->audit('webhooks.endpoint_activated', $endpoint, ['active' => false], ['active' => true]);
        }

        return $this->redirectWithStatus('admin.webhooks.index', 'Endpunkt aktiviert.');
    }

    public function deliveries(Request $request): View
    {
        $this->requirePermission('webhooks.manage');

        $status = (string) $request->query('status', '');
        $endpointId = (int) $request->query('endpoint', '0');

        $deliveries = $this->deliveryQuery()
            ->with(['endpoint:id,name,url', 'outbox:id,event_id,event_type,entity_type,entity_id,occurred_at'])
            ->when($status !== '', static fn (Builder $q) => $q->where('webhook_deliveries.status', $status))
            ->when($endpointId > 0, static fn (Builder $q) => $q->where('webhook_deliveries.endpoint_id', $endpointId))
            ->orderByDesc('webhook_deliveries.id')
            ->paginate($this->perPage())
            ->withQueryString();

        return view('admin::webhooks.deliveries', [
            'deliveries' => $deliveries,
            'endpoints' => WebhookEndpoint::query()->orderBy('name')->get(),
            'statuses' => [WebhookDelivery::STATUS_PENDING, WebhookDelivery::STATUS_DELIVERED, WebhookDelivery::STATUS_FAILED, WebhookDelivery::STATUS_DEAD, WebhookDelivery::STATUS_SKIPPED],
            'filter' => ['status' => $status, 'endpoint' => $endpointId],
            'title' => 'Zustellungen',
        ]);
    }

    public function dlq(Request $request): View
    {
        $this->requirePermission('webhooks.manage');

        $deliveries = $this->deliveryQuery()
            ->with(['endpoint:id,name,url', 'outbox:id,event_id,event_type,entity_type,entity_id,occurred_at'])
            ->where('webhook_deliveries.status', WebhookDelivery::STATUS_DEAD)
            ->orderByDesc('webhook_deliveries.dead_at')
            ->orderByDesc('webhook_deliveries.id')
            ->paginate($this->perPage())
            ->withQueryString();

        return view('admin::webhooks.deliveries', [
            'deliveries' => $deliveries,
            'endpoints' => collect(),
            'statuses' => [],
            'filter' => ['status' => WebhookDelivery::STATUS_DEAD, 'endpoint' => 0],
            'title' => 'Webhook-DLQ',
        ]);
    }

    public function redeliver(Request $request, int $delivery): RedirectResponse
    {
        $this->requirePermission('webhooks.manage');

        /** @var WebhookDelivery $model */
        $model = $this->deliveryQuery()->whereKey($delivery)->firstOrFail();
        $status = (string) $model->status;

        if (! in_array($status, [WebhookDelivery::STATUS_FAILED, WebhookDelivery::STATUS_DEAD, WebhookDelivery::STATUS_SKIPPED], true)) {
            return $this->redirectWithWarning('admin.webhooks.deliveries.index', 'Nur Zustellungen mit Status failed, dead oder skipped können erneut angestoßen werden.');
        }

        $before = ['status' => $status, 'attempts' => (int) $model->attempts, 'dead_at' => $model->dead_at?->toIso8601String()];

        $model->forceFill([
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
            'dead_at' => null,
            'next_attempt_at' => CarbonImmutable::now(),
        ])->save();

        $this->bus->dispatch((new DeliverWebhookJob((int) $model->getKey()))->onQueue((string) config('hub.webhooks.queue', 'default')));

        $this->audit('webhooks.delivery_redelivered', $model, $before, ['status' => WebhookDelivery::STATUS_PENDING, 'endpoint_id' => (int) $model->endpoint_id]);

        return $this->redirectWithStatus('admin.webhooks.deliveries.index', 'Zustellung erneut eingereiht.');
    }

    /**
     * Explizite Suche statt Route-Model-Binding, damit der Organization-Scope (Kontext aus admin.access) greift.
     */
    private function findEndpoint(int $id): WebhookEndpoint
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = WebhookEndpoint::query()->whereKey($id)->firstOrFail();

        return $endpoint;
    }

    /**
     * Zustellungen ausschließlich der Endpunkte des eigenen Mandanten.
     *
     * @return Builder<WebhookDelivery>
     */
    private function deliveryQuery(): Builder
    {
        $query = WebhookDelivery::query();
        $query->whereIn('endpoint_id', WebhookEndpoint::query()->select('id'));

        return $query;
    }
}
