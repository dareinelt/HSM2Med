<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Request;
use App\Http\Response;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $reports = $this->app->reportRepository();
        $imports = $this->app->importRepository();
        return Response::html($this->view->render('dashboard', [
            'title' => 'Dashboard',
            'stats' => $reports->statistics(),
            'latestReports' => $reports->search([], 5)['rows'],
            'latestImports' => $imports->list(5)['rows'],
        ], 'dashboard'));
    }
}
