<?php
$targetDir = "uploads/";

// Ensure folder exists
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

if (isset($_FILES["file"])) {
    $fileName = basename($_FILES["file"]["name"]);
    $uniqueName = uniqid() . "_" . $fileName; // unique filename
    $targetFile = $targetDir . $uniqueName;

    $fileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    $allowed = ["pdf", "docx"];

    if (!in_array($fileType, $allowed)) {
        echo "Only PDF and DOCX files are allowed.";
        exit;
    }

    if (move_uploaded_file($_FILES["file"]["tmp_name"], $targetFile)) {
        // Generate download link
        $downloadLink = "download.php?file=" . urlencode($uniqueName);
        echo "<div style='font-family:Segoe UI; text-align:center; margin-top:50px;'>";
        echo "<h3 style='color:green;'>File uploaded successfully!</h3>";
        echo "Download Link: <a href='$downloadLink'>$downloadLink</a>";
        echo "</div>";
    } else {
        echo "Error uploading file.";
    }
}
?>
