<?php

declare(strict_types=1);

namespace App\Http\Controllers\Population;

use App\Http\Controllers\Controller;
use App\Services\Population\AnomalyService;
use Inertia\Inertia;
use Inertia\Response;

final class AnomalyController extends Controller
{
    public function index(AnomalyService $anomalies): Response
    {
        return Inertia::render('Kependudukan/Anomali/Index', [
            'report' => $anomalies->report(),
        ]);
    }
}
