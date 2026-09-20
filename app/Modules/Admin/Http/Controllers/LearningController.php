<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\DecideLearningSuggestionRequest;
use App\Modules\Admin\Http\Requests\StartLearningRunRequest;
use App\Modules\Ai\Enums\SuggestionStatus;
use App\Modules\Ai\Services\AiSuggestionService;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Learning\Enums\LearningKind;
use App\Modules\Learning\Exceptions\NoConnectionForKindException;
use App\Modules\Learning\Jobs\RunLearningJob;
use App\Modules\Learning\Models\LearningRun;
use App\Modules\Learning\Services\LearningRunService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Lernphase Immoware24: erkundet lesend die aktuelle Struktur über WebDAV, CardDAV, CalDAV und Dateiexporte,
 * vergleicht mit dem vorigen Lauf und kann eine KI-Auswertung anstoßen. Ändert nie Immoware24 und keine
 * Konfigurationsdatei; Vorschläge sind zu prüfen und bei Bedarf von Hand zu übernehmen. Recht learning.manage.
 */
final class LearningController extends AdminController
{
    public function __construct(private readonly LearningRunService $runs) {}

    public function index(Request $request): View
    {
        $this->requirePermission('learning.manage');

        return view('admin::learning.index', [
            'title' => 'Lernphase Immoware24',
            'runs' => LearningRun::query()
                ->with(['connection:id,name', 'triggeredBy:id,name', 'aiSuggestion:id,status'])
                ->orderByDesc('id')
                ->paginate($this->perPage())
                ->withQueryString(),
            'kinds' => LearningKind::cases(),
            'connectionsByKind' => $this->connectionsByKind(),
        ]);
    }

    public function store(StartLearningRunRequest $request): RedirectResponse
    {
        $user = $this->currentUser($request);
        $kind = LearningKind::from((string) $request->validated('kind'));
        $connectionId = $request->validated('connection_id') !== null ? (int) $request->validated('connection_id') : null;

        try {
            $connection = $this->runs->resolveConnection($kind, (int) $user->getAttribute('organization_id'), $connectionId);
        } catch (NoConnectionForKindException $e) {
            return $this->redirectWithWarning('admin.learning.index', $e->getMessage());
        }

        $run = $this->runs->start((int) $user->getAttribute('organization_id'), $kind, $connection?->getKey(), $user);
        RunLearningJob::dispatch($run->getKey(), (bool) $request->validated('with_ai'));
        $this->audit('learning.run_started', $run, [], ['kind' => $kind->value, 'connection_id' => $connection?->getKey()]);

        return $this->redirectWithStatus('admin.learning.index', sprintf('Lernlauf %s gestartet (%s). Ergebnis erscheint nach Abschluss des Hintergrundjobs.', $run->getKey(), $kind->label()));
    }

    public function show(Request $request, LearningRun $run): View
    {
        $this->requirePermission('learning.manage');
        $run->load(['connection:id,name', 'triggeredBy:id,name', 'previousRun:id,created_at', 'aiSuggestion']);

        return view('admin::learning.show', ['title' => 'Lernlauf #'.$run->getKey(), 'run' => $run]);
    }

    public function decide(DecideLearningSuggestionRequest $request, LearningRun $run, AiSuggestionService $ai): RedirectResponse
    {
        $user = $this->currentUser($request);
        $suggestion = $run->aiSuggestion;

        if ($suggestion === null) {
            return $this->redirectWithWarning('admin.learning.show', 'Zu diesem Lauf liegt kein KI-Vorschlag vor.', ['run' => $run->getKey()]);
        }

        $decision = SuggestionStatus::from((string) $request->validated('decision'));

        try {
            $ai->decide($suggestion, $user, $decision);
        } catch (\LogicException $e) {
            return $this->redirectWithWarning('admin.learning.show', $e->getMessage(), ['run' => $run->getKey()]);
        }

        $this->audit('learning.suggestion_decided', $suggestion, [], ['decision' => $decision->value]);

        return $this->redirectWithStatus('admin.learning.show', sprintf('Vorschlag %s. Eine Übernahme in die Konfigurationsdatei bleibt ein manueller Schritt.', $decision->label()), ['run' => $run->getKey()]);
    }

    /**
     * @return array<string, Collection<int, ImmowareConnection>>
     */
    private function connectionsByKind(): array
    {
        $result = [];

        foreach (LearningKind::cases() as $kind) {
            $result[$kind->value] = $this->connectionsFor($kind);
        }

        return $result;
    }

    /**
     * @return Collection<int, ImmowareConnection>
     */
    private function connectionsFor(LearningKind $kind): Collection
    {
        if (! $kind->requiresConnection()) {
            return new Collection;
        }

        $query = ImmowareConnection::query();
        $query->whereIn('connector_type', $kind->connectorTypeValues());
        $query->orderBy('name');

        return $query->get();
    }
}
