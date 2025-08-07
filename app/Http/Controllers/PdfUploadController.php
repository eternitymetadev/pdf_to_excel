<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $sheet->fromArray(
            ['File', 'Order Number', 'Order Date', 'Invoice Number', 'Invoice Details', 'Shipping Address', 'Shipping GST'],
            null,
            'A1'
        );

        $row = 2;

        foreach ($request->file('pdfs') as $uploadedFile) {
            $pdf = $parser->parseFile($uploadedFile->getRealPath());
            $text = $pdf->getText();
            $lines = explode("\n", $text);

            // Initialize fields
            $orderNumber = $invoiceNumber = $orderDate = $invoiceDetails = '';
            $shippingAddress = '';
            $shippingGst = '';

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

            // Extract Shipping Address block
            preg_match('/Shipping Address\s*:\s*(.*?)\n(?:\s*\n|\r\n|\n)/s', $text, $shippingBlock);
            $shippingBlockText = trim($shippingBlock[1] ?? '');
            $shippingLines = explode("\n", $shippingBlockText);
            $shippingAddress = implode(', ', array_map('trim', $shippingLines));

            // Extract the GST number from the "Sold By" section
            preg_match('/Sold By\s*:.*?GST Registration No:\s*(\w+)/s', $text, $gstMatch);
            $shippingGst = trim($gstMatch[1] ?? '');


            $shippingLines = array_filter(explode("\n", $shippingBlockText), function ($line) {
                return stripos($line, 'GST Registration No:') === false;
            });

            $shippingAddress = implode(", ", array_map('trim', $shippingLines));

            $sheet->fromArray([
                $uploadedFile->getClientOriginalName(),
                $orderNumber,
                $orderDate,
                $invoiceNumber,
                $invoiceDetails,
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
