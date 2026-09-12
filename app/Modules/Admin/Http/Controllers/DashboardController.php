<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\DashboardService;
use Illuminate\Contracts\View\View;

final class DashboardController extends AdminController
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function __invoke(): View
    {
        return view('admin::dashboard', $this->dashboard->build());
    }
}
