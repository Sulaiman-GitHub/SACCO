<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\PortfolioReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantReportController extends Controller
{
    /**
     * Printable management summary with export options.
     */
    public function index(PortfolioReportService $reports): View
    {
        return view('tenant.reports.index', [
            'snapshot' => $reports->snapshot(),
            'topRiskLoans' => $reports->topRiskLoans(10),
            'generatedAt' => Carbon::now(),
        ]);
    }

    /**
     * Export the savings ledger as CSV, optionally within a date range.
     */
    public function exportSavings(Request $request, PortfolioReportService $reports): StreamedResponse
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        return $this->streamCsv(
            'savings-ledger',
            ['Reference', 'Date', 'Member No', 'Member', 'Type', 'Amount (UGX)', 'Method', 'Payment Ref', 'Recorded By'],
            $reports->savingsExportRows($validated['start_date'] ?? null, $validated['end_date'] ?? null)
        );
    }

    /**
     * Export the loan portfolio as CSV, optionally filtered by status.
     */
    public function exportLoans(Request $request, PortfolioReportService $reports): StreamedResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'disbursed', 'overdue', 'defaulted', 'closed'])],
        ]);

        return $this->streamCsv(
            'loan-portfolio',
            ['Loan Ref', 'Member No', 'Member', 'Status', 'Principal (UGX)', 'Total Payable (UGX)', 'Repaid (UGX)', 'Balance (UGX)', 'Applied', 'Due Date', 'Days Overdue', 'Risk Score', 'Lending Policy Recommendation'],
            $reports->loansExportRows($validated['status'] ?? null)
        );
    }

    /**
     * Stream rows as a CSV download.
     *
     * @param  list<string>  $headings
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    private function streamCsv(string $name, array $headings, iterable $rows): StreamedResponse
    {
        $filename = $name.'-'.Carbon::now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headings);

            foreach ($rows as $row) {
                fputcsv($out, array_map($this->neutraliseFormula(...), $row));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Prefix cells that spreadsheets would execute as formulas (member names are user-entered).
     */
    private function neutraliseFormula(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
