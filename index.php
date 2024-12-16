
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR Image Uploader</title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            max-width: 900px;
            padding: 0;
            margin: 0 auto;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background-color: #f9f9f9;
        }
        h1 {
            text-align: center;
        }
        form {
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ccc;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            background-color: #fff;
        }
        label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
        }
        input[type="file"],
        input[type="text"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button[type="submit"] {
            background-color: #4CAF50;
            color: white;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            width: 100%;
            border-radius: 4px;
        }
        button[type="submit"]:hover {
            background-color: #45a049;
        }
        pre {
            background-color: #f1f1f1;
            padding: 20px;
            overflow-x: auto;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background-color: #f2f2f2;
        }
    </style>
</head>
<body>
    <?php
require_once 'vendor/autoload.php';
use Google\Cloud\Vision\V1\ImageAnnotatorClient;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ocr_image']) && isset($_POST['chatgpt_api_key'])) {
    $uploadDir = 'uploads';
    $googleCredentials = "google-service.json";
    $uploadedFile = $_FILES['ocr_image']['tmp_name'];
    $fileName = basename($_FILES['ocr_image']['name']);
    $targetPath = "$uploadDir/$fileName";

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    if (move_uploaded_file($uploadedFile, $targetPath)) {
        try {
            $client = new ImageAnnotatorClient(['credentials' => $googleCredentials]);
            $imageContent = file_get_contents($targetPath);
            $response = $client->textDetection($imageContent);
            $texts = $response->getTextAnnotations();

            if (!$texts || count($texts) === 0) {
                $output = "<p style='color: red'>Error: No text found in image.</p>";
            } else {
                $extractedText = $texts[0]->getDescription();   
                $chatGptApiKey = $_POST['chatgpt_api_key'];
                $chatGptApiUrl = 'https://api.openai.com/v1/chat/completions';

                $postData = json_encode([
                    'model' => 'gpt-4',
                    'messages' => [['role' => 'system', 'content' => 'User: ' ."I have text extracted from an image using Google Vision OCR. The text may include user information such as the first name, last name, company, email, and phone number. Analyze the text and extract this information, returning it in the following JSON format:\n\n{\n  \"first_name\": \"\",\n  \"last_name\": \"\",\n  \"company\": \"\",\n  \"email\": \"\",\n  \"phone\": \"\"\n}\n\nInstructions:\nExtract the user's first name and last name if they are available.\nIdentify the company name if mentioned in the text.\nExtract the email address and phone number.\nIf any field cannot be determined, assign 'N/A' to that field.\nOutput the JSON response.\nBe flexible with formatting (e.g., phone numbers may include dashes, spaces, or parentheses).\nHere is the extracted text:\n\n" . $extractedText]],
                    'max_tokens' => 150,
                    'temperature' => 0.7,
                ]);

                $ch = curl_init($chatGptApiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $chatGptApiKey,
                ]);

                $response = curl_exec($ch);
                if (curl_errno($ch)) {
                    $chatGptResponse = 'Error: ' . curl_error($ch);
                } else {
                    $responseData = json_decode($response, true);
                    if (isset($responseData['choices'][0]['message']['content'])) {
                        $chatGptResponse = $responseData['choices'][0]['message']['content'];
                    } else {
                        $output = "<p style='color: red'>No response from ChatGPT.</p>";
                    }
            
                    $leadData = json_decode($chatGptResponse, true);
            
                    $tableRows = '';
                    $mauticPostData = [
                        'firstname' => $leadData['first_name'] ?? 'N/A',
                        'lastname' => $leadData['last_name'] ?? 'N/A',
                        'company' => $leadData['company'] ?? 'N/A',
                        'email' => $leadData['email'] ?? 'N/A',
                        'phone' => $leadData['phone'] ?? 'N/A',
                    ];
                    foreach ($mauticPostData as $key => $value) {
                        $tableRows .= '<tr><td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' . ucfirst($key) . '</td><td style="padding: 8px; text-align: left; border: 1px solid #ddd;">' . $value . '</td></tr>';
                    }
                    $output = '<table style="border-collapse: collapse; width: 100%;"><tr style="background-color: #f2f2f2;"><th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Field</th><th style="padding: 8px; text-align: left; border: 1px solid #ddd;">Value</th></tr>' . $tableRows . '</table>';
            
                }
                curl_close($ch);
            }

        } catch (Exception $e) {
            $output = "Error: " . $e->getMessage();
        }
    } else {
        $output = "Failed to upload the file.";
    }
}
?>

    <form method="POST" enctype="multipart/form-data">
        <h1>Upload Visiting Card</h1>
        <p>This page extracts text from an image of a visiting card using Google Vision OCR and then uses ChatGPT to parse the text and extract the first name, last name, company, email, and phone number.</p>
        <label for="ocr_image">Choose an image:</label>
        <input type="file" name="ocr_image" id="ocr_image" accept="image/*" required>
        <label for="chatgpt_api_key">ChatGPT API Key:</label>
        <input type="text" name="chatgpt_api_key" id="chatgpt_api_key" required>
        <button type="submit">Extract Text</button>
    </form>
    
    <?php if (isset($output)): ?>
        <h2>Response:</h2>
        <pre><?php echo $output; ?></pre>
    <?php endif; ?>
</body>
</html>

