<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExtractPdfData extends Command
{
    protected $signature = 'pdf:extract';
    protected $description = 'Extract data from fixed-format PDFs and export to Excel';

    public function handle()
    {
        $parser = new Parser();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Excel headers
        $sheet->fromArray(
            ['File', 'Order Number', 'Order Date', 'Invoice Number', 'Invoice Details', 'Shipping Address', 'Shipping GST'],
            null,
            'A1'
        );

        $pdfDir = storage_path('app/pdfs');
        $files = glob($pdfDir . '/*.pdf');
        $row = 2;

        foreach ($files as $file) {
            $pdf = $parser->parseFile($file);
            $text = $pdf->getText();
            $lines = explode("\n", $text);

            // Initialize fields
            $orderNumber = $invoiceNumber = $orderDate = $invoiceDetails = '';
            $shippingAddress = '';
            $shippingGst = '';

            // Extract Order Number, Invoice Number, Order Date, Invoice Details
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

            // Extract full Shipping Address block
            preg_match('/Shipping Address\s*:\s*(.*?)\n(?:\s*\n|\r\n|\n)/s', $text, $shippingBlock);
            $shippingBlockText = trim($shippingBlock[1] ?? '');

            // Extract Shipping GST from block
            preg_match('/GST Registration No:\s*(.*)/', $shippingBlockText, $shippingGstMatch);
            $shippingGst = trim($shippingGstMatch[1] ?? '');

            // Remove 'GST Registration No' from shipping address if included
            $shippingLines = array_filter(explode("\n", $shippingBlockText), function ($line) {
                return stripos($line, 'GST Registration No:') === false;
            });

            $shippingAddress = implode(", ", array_map('trim', $shippingLines));

            // Write data to Excel
            $sheet->fromArray([
                basename($file),
                $orderNumber,
                $orderDate,
                $invoiceNumber,
                $invoiceDetails,
                $shippingAddress,
                $shippingGst
            ], null, 'A' . $row);

            $row++;
        }

        // Save the file
        $outputPath = storage_path('app/output/extracted_data.xlsx');
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);

        $this->info("✅ Extraction complete! Excel saved at: $outputPath");
    }
}
