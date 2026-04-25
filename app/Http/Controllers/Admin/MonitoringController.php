<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MonitoringService;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    public function index(): View
    {
        $monitoring = app(MonitoringService::class);

        return view('admin.monitoring.index', [
            'live' => $monitoring->getLivePaymentsStats(),
            'webhook' => $monitoring->getWebhookHealth(),
            'integrity' => $monitoring->getDataIntegrityStats(),
            'finance' => $monitoring->getFinanceStats(),
            'alerts' => $monitoring->evaluateAlerts(),
        ]);
    }
}

