<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Report\ReportService;
use App\Repository\ReportRepository;

final class ReportController extends Controller
{
    private const int PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $filters = [];
        foreach (ReportRepository::FILTER_KEYS as $key) {
            $filters[$key] = mb_substr(trim($request->query($key)), 0, 200);
        }
        $page = self::page($request->query('page', '1'));
        $result = $this->app->reportRepository()->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return Response::html($this->view->render('reports/index', [
            'title' => 'Berichte',
            'filters' => $filters,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
        ], 'reports'));
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $data = $this->app->reportService()->load(self::id($params)) ?? throw HttpException::notFound('Der Bericht wurde nicht gefunden.');
        $related = $data->report['device_id'] !== null
            ? $this->app->reportRepository()->relatedByDevice((int) $data->report['device_id'], $data->id())
            : [];
        return Response::html($this->view->render('reports/show', [
            'title' => 'Bericht Nr. ' . $data->id(),
            'data' => $data,
            'related' => $related,
            'cards' => $this->app->patientCardService()->cardsForReport($data->id()),
            'rawDefault' => $this->app->config->pdfRawAppendixDefault,
        ], 'reports'));
    }

    /**
     * PDF ausschliesslich aus dem gespeicherten Datenbank-Snapshot.
     *
     * @param array<string, string> $params
     */
    public function pdf(Request $request, array $params): Response
    {
        $service = $this->app->reportService();
        $data = $service->load(self::id($params)) ?? throw HttpException::notFound('Der Bericht wurde nicht gefunden.');
        $raw = $request->query('raw');
        $includeRaw = $raw === '' ? $this->app->config->pdfRawAppendixDefault : $raw === '1';
        $pdf = $service->renderPdf($data, $includeRaw, $this->app->clock()->now());
        return Response::pdf($pdf, ReportService::pdfFilename($data), $request->query('download') === '1');
    }
}
