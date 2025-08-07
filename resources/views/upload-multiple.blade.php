<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Multiple PDFs</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f7fafc;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 400px;
            background: #fff;
            margin: 60px auto;
            padding: 40px 30px 32px 30px;
            border-radius: 14px;
            box-shadow: 0 3px 20px 2px #0002;
        }
        h2 {
            color: #26396b;
            text-align: center;
            letter-spacing: 1px;
            margin-bottom: 26px;
        }
        input[type="file"] {
            border: 2px dashed #8ebfff;
            border-radius: 7px;
            padding: 15px;
            width: 90%;
            margin-bottom: 18px;
            background: #f4f8fd;
            transition: border 0.3s;
        }
        input[type="file"]:focus {
            border-color: #3469c7;
        }
        button {
            width: 100%;
            padding: 13px 0;
            background: linear-gradient(90deg, #307ffe 60%, #7fbaff 100%);
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 7px;
            box-shadow: 0 2px 12px #53adec44;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover {
            background: linear-gradient(90deg, #2967cb 40%, #70adef 100%);
        }
        .errors {
            background: #ffeaea;
            color: #d13c21;
            border: 1px solid #ffbdbd;
            border-radius: 5px;
            padding: 14px 18px;
            margin-bottom: 18px;
        }
        .errors ul {
            padding-left: 18px;
            margin: 0;
        }
        @media (max-width: 500px) {
            .container {
                padding: 24px 10px;
                max-width: 96vw;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Upload Invoices (Multiple PDFs)</h2>

        @if ($errors->any())
            <div class="errors">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('upload.handle') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="file" name="pdfs[]" multiple accept=".pdf" required>
            <button type="submit">Upload & Download Excel</button>
        </form>
    </div>
</body>
</html>
