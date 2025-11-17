<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Smalot\PdfParser\Parser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        // Headers with separate billing/shipping address columns and HSN
        $headers = [
            'File',
            'Sl.No',
            'Order Number',
            'Order Date',
            'Invoice Number',
            'Invoice Date',
            'Invoice Details',
            'Billing Name',
            'Billing Address',
            'Billing GST',
            'Shipping Name',
            'Shipping Address',
            'Shipping GST',
            'Description',
            'HSN Code',
            'Unit Price',
            'Discount',
            'Qty',
            'Net Amount',
            'Tax Rate',
            'Tax Type',
            'Tax Amount',
            'Total Amount'
        ];

        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:W1')->getFont()->setBold(true);
        $sheet->getStyle('A1:W1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Set column widths
        $columnWidths = [
            'A' => 25,  // File
            'B' => 20,  // Order Number
            'C' => 12,  // Order Date
            'D' => 20,  // Invoice Number
            'E' => 12,  // Invoice Date
            'F' => 25,  // Invoice Details
            'G' => 30,  // Billing Name
            'H' => 40,  // Billing Address
            'I' => 18,  // Billing GST
            'J' => 30,  // Shipping Name
            'K' => 40,  // Shipping Address
            'L' => 18,  // Shipping GST
            'M' => 8,   // Sl.No
            'N' => 60,  // Description
            'O' => 12,  // HSN Code
            'P' => 12,  // Unit Price
            'Q' => 12,  // Discount
            'R' => 8,   // Qty
            'S' => 12,  // Net Amount
            'T' => 10,  // Tax Rate
            'U' => 10,  // Tax Type
            'V' => 12,  // Tax Amount
            'W' => 12   // Total Amount
        ];
        foreach ($columnWidths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $row = 2;

        foreach ($request->file('pdfs') as $uploadedFile) {
            try {
                $pdf = $parser->parseFile($uploadedFile->getRealPath());
                $text = $pdf->getText();

                // Extract data using OpenAI
                $extractedData = $this->extractInvoiceDataWithAI($text);
                
                $fileName = $uploadedFile->getClientOriginalName();
                $invoiceInfo = $extractedData['invoice_info'] ?? [];
                $items = $extractedData['items'] ?? [];

                // If no items extracted, add at least one row with invoice info
                if (empty($items)) {
                    $items = [[
                        'sl_no' => '',
                        'description' => '',
                        'hsn_code' => '',
                        'unit_price' => '',
                        'discount' => '',
                        'qty' => '',
                        'net_amount' => '',
                        'tax_rate' => '',
                        'tax_type' => '',
                        'tax_amount' => '',
                        'total_amount' => ''
                    ]];
                }

                // Write rows - one row per item with invoice info repeated
                foreach ($items as $item) {
                    $sheet->fromArray([
                        $fileName,
                        $item['sl_no'] ?? '',
                        $invoiceInfo['order_number'] ?? '',
                        $invoiceInfo['order_date'] ?? '',
                        $invoiceInfo['invoice_number'] ?? '',
                        $invoiceInfo['invoice_date'] ?? '',
                        $invoiceInfo['invoice_details'] ?? '',
                        $invoiceInfo['billing_name'] ?? '',
                        $invoiceInfo['billing_address'] ?? '',
                        $invoiceInfo['billing_gst'] ?? '',
                        $invoiceInfo['shipping_name'] ?? '',
                        $invoiceInfo['shipping_address'] ?? '',
                        $invoiceInfo['shipping_gst'] ?? '',
                        $item['description'] ?? '',
                        $item['hsn_code'] ?? '',
                        $item['unit_price'] ?? '',
                        $item['discount'] ?? '',
                        $item['qty'] ?? '',
                        $item['net_amount'] ?? '',
                        $item['tax_rate'] ?? '',
                        $item['tax_type'] ?? '',
                        $item['tax_amount'] ?? '',
                        $item['total_amount'] ?? ''
                    ], null, 'A' . $row);
                    $row++;
                }
            } catch (\Exception $e) {
                Log::error('PDF Processing Error: ' . $e->getMessage());
                // Add error row
                $sheet->fromArray([
                    $uploadedFile->getClientOriginalName(),
                    'ERROR: ' . $e->getMessage(),
                    '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''
                ], null, 'A' . $row);
                $row++;
            }
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'pdf_extract_') . '.xlsx';
        $writer->save($tempFile);

        return response()->download($tempFile, 'extracted_data.xlsx')->deleteFileAfterSend(true);
    }

    private function extractInvoiceDataWithAI($text)
    {
        $apiKey = env('OPENAI_API_KEY');
        
        if (empty($apiKey)) {
            throw new \Exception('OpenAI API key not configured');
        }

        $prompt = <<<PROMPT
        Extract complete invoice data from this Amazon invoice text. Return ONLY valid JSON with no markdown formatting.

        Extract TWO sections:
        1. invoice_info: Header information (once per invoice)
        2. items: Array of line items (products + shipping charges)

        JSON Structure:
        {
        "invoice_info": {
            "order_number": "405-2547428-2436348",
            "order_date": "28.10.2025",
            "invoice_number": "DEL5-3777179",
            "invoice_date": "28.10.2025",
            "invoice_details": "HR-DEL5-297683823-2526",
            "billing_name": "TRUMART ECOM PRIVATE LIMITED",
            "billing_address": "Morta Village Gate, Khasra No. 938, Meerut Road, Ghaziabad, GHAZIABAD, UP, 201003",
            "billing_gst": "09AAKCT9816C1Z1",
            "shipping_name": "TRUMART ECOM PRIVATE LIMITED",
            "shipping_address": "Nidhi Sharma, TRUMART ECOM PRIVATE LIMITED, B-103, Bestech Business Tower, Sector 66, MOHALI, PUNJAB, 160062",
            "shipping_gst": "03"
        },
        "items": [
            {
            "sl_no": "1",
            "description": "WD-40 Pidilite Multipurpose Spray, Lubricant, Rust Remover, Squeak Noise Remover, Stain Remover, And Cleaning Agent, 170G",
            "hsn_code": "34031900",
            "unit_price": "181.36",
            "discount": "0.00",
            "qty": "1",
            "net_amount": "181.36",
            "tax_rate": "18%",
            "tax_type": "IGST",
            "tax_amount": "32.64",
            "total_amount": "214.00"
            },
            {
            "sl_no": "",
            "description": "Shipping Charges",
            "hsn_code": "34031900",
            "unit_price": "33.90",
            "discount": "-33.90",
            "qty": "",
            "net_amount": "0.00",
            "tax_rate": "18%",
            "tax_type": "IGST",
            "tax_amount": "0.00",
            "total_amount": "0.00"
            }
        ]
        }

        Extraction Rules:

        Invoice Info:
        - order_number: From "Order Number:" field
        - order_date: From "Order Date:" field
        - invoice_number: From "Invoice Number :" field
        - invoice_date: From "Invoice Date :" field
        - invoice_details: From "Invoice Details :" field
        - billing_name: First company/person name after "Billing Address :"
        - billing_address: Complete address from "Billing Address :" section (multiple lines combined with commas)
        - billing_gst: From "GST Registration No:" in billing section (extract only the GST number like "09AAKCT9816C1Z1")
        - shipping_name: First company/person name after "Shipping Address :"
        - shipping_address: Complete address from "Shipping Address :" section (multiple lines combined with commas)
        - shipping_gst: From "State/UT Code:" in shipping section (extract only the number like "03")

        Items (CRITICAL - Extract each product AND its shipping charge as separate items):
        - For main products:
        - sl_no: Sequential number (1, 2, 3...)
        - description: Clean product name ONLY - remove HSN codes, product IDs in parentheses like (B07MDKM7DZ), pipe symbols
        - hsn_code: Extract HSN code from "HSN:34031900" format (just the number "34031900")
        - Extract all numeric fields from the data row
        
        - For shipping charges (appears after each product):
        - sl_no: Empty string ""
        - description: "Shipping Charges"
        - hsn_code: Extract HSN code from the shipping charges line
        - Extract all numeric fields

        Numeric Fields Rules:
        - Remove all ₹ symbols and commas from amounts
        - Keep negative values as-is (e.g., "-33.90")
        - unit_price: First numeric value
        - discount: Second numeric value
        - qty: Third numeric value (usually "1" for products, empty "" for shipping)
        - net_amount: Fourth numeric value
        - tax_rate: Extract percentage like "18%"
        - tax_type: Extract tax type like "IGST", "CGST", "SGST"
        - tax_amount: Second-to-last numeric value
        - total_amount: Last numeric value

        Invoice Text:
        $text

        Return ONLY the JSON object, no markdown code blocks or extra text.
        PROMPT;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a precise invoice data extraction assistant. Always return valid JSON only, no markdown formatting or explanations.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.1,
                'max_tokens' => 3000
            ]);

            if (!$response->successful()) {
                Log::error('OpenAI API Error: ' . $response->body());
                throw new \Exception('OpenAI API request failed');
            }

            $result = $response->json();
            $content = $result['choices'][0]['message']['content'] ?? '';
            
            // Remove markdown code blocks if present
            $content = preg_replace('/```json\s*|\s*```/', '', $content);
            $content = trim($content);
            
            //Log::info('OpenAI Response:', ['content' => $content]);
            
            $data = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('JSON Decode Error: ' . json_last_error_msg());
                throw new \Exception('Failed to parse AI response as JSON');
            }
            
            return $data ?? ['invoice_info' => [], 'items' => []];
            
        } catch (\Exception $e) {
            Log::error('AI Extraction Error: ' . $e->getMessage());
            throw $e;
        }
    }
}