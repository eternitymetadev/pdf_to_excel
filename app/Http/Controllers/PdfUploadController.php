<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class PdfUploadController extends Controller
{
    public function showForm()
    {
        return view('upload-multiple');
    }

    public function handleUpload(Request $request)
    {
        $request->validate([
            'pdfs.*' => 'required|file|mimes:pdf|max:10240',
        ]);

        $parser = new Parser();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Updated header to match required order
        $headers = [
            'File',
            'Order Number',
            'Order Date',
            'Invoice Number',
            'Invoice Details',
            'Billing Name',
            'Shipping Address',
            'Shipping GST'
        ];

        $sheet->fromArray($headers, null, 'A1');

        // Style header
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->getStyle('A1:H1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $row = 2;

        foreach ($request->file('pdfs') as $uploadedFile) {
            $pdf = $parser->parseFile($uploadedFile->getRealPath());
            $text = $pdf->getText();
            $lines = explode("\n", $text);

            $orderNumber = $invoiceNumber = $orderDate = $invoiceDetails = '';
            $shippingAddress = '';
            $shippingGst = '';
            $billingName = '';
            $billingGst = '';

            // Extract Order Number & Invoice Number
            foreach ($lines as $line) {
                if (stripos($line, 'Order Number:') !== false && stripos($line, 'Invoice Number') !== false) {
                    preg_match('/Order Number:\s*([^\s]+)/', $line, $orderMatch);
                    preg_match('/Invoice Number\s*:\s*([^\s]+)/', $line, $invoiceMatch);
                    $orderNumber = $orderMatch[1] ?? '';
                    $invoiceNumber = $invoiceMatch[1] ?? '';
                }

                if (stripos($line, 'Order Date:') !== false && stripos($line, 'Invoice Details') !== false) {
                    preg_match('/Order Date:\s*([^\s]+)/', $line, $dateMatch);
                    preg_match('/Invoice Details\s*:\s*([^\s]+)/', $line, $detailsMatch);
                    $orderDate = $dateMatch[1] ?? '';
                    $invoiceDetails = $detailsMatch[1] ?? '';
                }
            }

            // --- Shipping Address block ---
            preg_match('/Shipping Address\s*:([\s\S]*?)(?:Place of supply|Place of Supply)/i', $text, $shippingBlock);
            $shippingBlockText = trim($shippingBlock[1] ?? '');
            //$shippingAddress = preg_replace('/\s+/', ' ', $shippingBlockText);

            // Extract Shipping GST anywhere in that block
            preg_match('/GST Registration No\s*:\s*([A-Z0-9]+)/i', $shippingBlockText, $gstMatch);
            $shippingGst = trim($gstMatch[1] ?? '');

            // Find "highlight-like" words (all-caps blocks often used by Amazon for emphasis)
            $highlightWords = [];
            if (preg_match_all('/\b[A-Z]{2,}(?:\s+[A-Z]{2,})*\b/', $shippingBlockText, $matches)) {
                $highlightWords = array_unique($matches[0]);
            }

            // Append highlights if found
            if (!empty($highlightWords)) {
                $shippingAddress =  implode(", ", $highlightWords);
            }

            // Billing Name (first line after "Billing Address :")
            preg_match('/Billing Address\s*:\s*(.+)\n/i', $text, $billingNameMatch);
            $billingName = trim($billingNameMatch[1] ?? '');

            // Billing GST (search after "Billing Address")
            preg_match('/Billing Address.*?GST Registration No:\s*([A-Z0-9]+)/s', $text, $billingGstMatch);
            $billingGst = trim($billingGstMatch[1] ?? '');

            // Write row in correct column order
            $sheet->fromArray([
                $uploadedFile->getClientOriginalName(),
                $orderNumber,
                $orderDate,
                $invoiceNumber,
                $invoiceDetails,
                $billingName,
                $shippingAddress,
                $shippingGst
            ], null, 'A' . $row);

            $row++;
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_extract_') . '.xlsx';
        $writer->save($tempFile);

        return response()->download($tempFile, 'extracted_data.xlsx')->deleteFileAfterSend(true);
    }
}
