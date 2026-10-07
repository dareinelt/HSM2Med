<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

/**
 * Importprotokoll (inkl. fehlgeschlagener Importe).
 */
final class ImportLogController extends Controller
{
    private const int PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $page = self::page($request->query('page', '1'));
        $status = $request->query('status');
        $status = in_array($status, ['completed', 'completed_with_warnings', 'completed_with_errors', 'failed'], true) ? $status : null;
        $result = $this->app->importRepository()->list(self::PER_PAGE, ($page - 1) * self::PER_PAGE, $status);
        return Response::html($this->view->render('imports/index', [
            'title' => 'Importprotokoll',
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'status' => $status,
        ], 'imports'));
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $repository = $this->app->importRepository();
        $import = $repository->find(self::id($params)) ?? throw HttpException::notFound('Der Import wurde nicht gefunden.');
        return Response::html($this->view->render('imports/show', [
            'title' => 'Import Nr. ' . $import['id'],
            'import' => $import,
            'issues' => $repository->issues((int) $import['id']),
        ], 'imports'));
    }
}
