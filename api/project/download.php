<?php
$targetDir = "uploads/";

if (isset($_GET["file"])) {
    $file = basename($_GET["file"]);
    $filePath = $targetDir . $file;

    if (file_exists($filePath)) {
        // Instead of forcing download immediately, show a page with a button
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>Download File</title>
            <style>
                body {
                    font-family: 'Segoe UI', Arial, sans-serif;
                    background: #f4f6f9;
                    text-align: center;
                    padding-top: 100px;
                }
                .btn {
                    background: #003366;
                    color: #fff;
                    padding: 12px 25px;
                    border-radius: 6px;
                    text-decoration: none;
                    font-size: 18px;
                    transition: background 0.3s ease;
                }
                .btn:hover {
                    background: #0055aa;
                }
            </style>
        </head>
        <body>
            <h2>Your file is ready</h2>
            <p>Click below to download:</p>
            <a class='btn' href='$targetDir$file' download>Download File</a>
            <p style='margin-top:20px;'>Direct link: <a href='$targetDir$file'>$targetDir$file</a></p>
        </body>
        </html>";
    } else {
        echo "File not found.";
    }
} else {
    echo "No file specified.";
}
?>
