<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$templatePath = '../assets/images/ticket_template.png';
if (!file_exists($templatePath)) {
    die('Template not found. Please upload ticket_template.png to assets/images/');
}

// Get image dimensions
$image = imagecreatefrompng($templatePath);
$width = imagesx($image);
$height = imagesy($image);
imagedestroy($image);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Find Template Positions</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f0f2f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 16px; }
        img { max-width: 100%; border: 1px solid #ddd; }
        .coordinates { margin-top: 20px; padding: 15px; background: #e0e7ff; border-radius: 8px; }
        .coord-input { margin: 10px 0; }
        label { display: inline-block; width: 150px; font-weight: bold; }
        input { width: 80px; padding: 5px; }
        button { background: #4f46e5; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; margin: 5px; }
        .instructions { background: #fef3c7; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Ticket Template Position Finder</h1>
        
        <div class="instructions">
            <strong>How to use:</strong>
            <ol>
                <li>Hover over the image below to see mouse coordinates</li>
                <li>Note the X and Y positions where you want text to appear</li>
                <li>Enter those positions in the form below</li>
                <li>Click "Generate Ticket" to test your positions</li>
            </ol>
        </div>
        
        <div id="image-container" style="position: relative; display: inline-block;">
            <img id="template-img" src="../assets/images/ticket_template.png?<?php echo time(); ?>" 
                 usemap="#template-map" style="max-width: 100%; cursor: crosshair;">
            <div id="coords-display" style="position: absolute; background: rgba(0,0,0,0.7); color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; display: none;"></div>
        </div>
        
        <div class="coordinates">
            <h3>Position Configuration</h3>
            <form id="posForm">
                <div class="coord-input">
                    <label>Event Name:</label>
                    X: <input type="number" name="event_name_x" id="event_name_x" value="250"> 
                    Y: <input type="number" name="event_name_y" id="event_name_y" value="100">
                </div>
                <div class="coord-input">
                    <label>Event Date:</label>
                    X: <input type="number" name="event_date_x" id="event_date_x" value="250"> 
                    Y: <input type="number" name="event_date_y" id="event_date_y" value="150">
                </div>
                <div class="coord-input">
                    <label>Event Time:</label>
                    X: <input type="number" name="event_time_x" id="event_time_x" value="250"> 
                    Y: <input type="number" name="event_time_y" id="event_time_y" value="180">
                </div>
                <div class="coord-input">
                    <label>Venue:</label>
                    X: <input type="number" name="venue_x" id="venue_x" value="250"> 
                    Y: <input type="number" name="venue_y" id="venue_y" value="210">
                </div>
                <div class="coord-input">
                    <label>Customer Name:</label>
                    X: <input type="number" name="customer_x" id="customer_x" value="250"> 
                    Y: <input type="number" name="customer_y" id="customer_y" value="280">
                </div>
                <div class="coord-input">
                    <label>Table ID:</label>
                    X: <input type="number" name="table_x" id="table_x" value="250"> 
                    Y: <input type="number" name="table_y" id="table_y" value="320">
                </div>
                <div class="coord-input">
                    <label>Ticket Type:</label>
                    X: <input type="number" name="type_x" id="type_x" value="250"> 
                    Y: <input type="number" name="type_y" id="type_y" value="360">
                </div>
                <div class="coord-input">
                    <label>QR Code X/Y:</label>
                    X: <input type="number" name="qr_x" id="qr_x" value="160"> 
                    Y: <input type="number" name="qr_y" id="qr_y" value="400">
                </div>
                <div class="coord-input">
                    <label>QR Code Size:</label>
                    <input type="number" name="qr_size" id="qr_size" value="180">
                </div>
                <div class="coord-input">
                    <label>Ticket Code Y:</label>
                    <input type="number" name="code_y" id="code_y" value="550">
                </div>
                
                <button type="button" onclick="testTicket()">Test with these positions</button>
            </form>
        </div>
        
        <div id="test-result" style="margin-top: 20px;"></div>
    </div>
    
    <script>
        const img = document.getElementById('template-img');
        const coordsDisplay = document.getElementById('coords-display');
        
        img.addEventListener('mousemove', function(e) {
            const rect = this.getBoundingClientRect();
            const scaleX = this.naturalWidth / rect.width;
            const scaleY = this.naturalHeight / rect.height;
            const x = Math.round((e.clientX - rect.left) * scaleX);
            const y = Math.round((e.clientY - rect.top) * scaleY);
            
            coordsDisplay.style.display = 'block';
            coordsDisplay.style.left = (e.clientX - rect.left + 10) + 'px';
            coordsDisplay.style.top = (e.clientY - rect.top - 20) + 'px';
            coordsDisplay.innerHTML = `X: ${x}, Y: ${y}`;
        });
        
        img.addEventListener('mouseleave', function() {
            coordsDisplay.style.display = 'none';
        });
        
        function testTicket() {
            const params = new URLSearchParams();
            params.set('test', '1');
            params.set('event_name_x', document.getElementById('event_name_x').value);
            params.set('event_name_y', document.getElementById('event_name_y').value);
            params.set('event_date_x', document.getElementById('event_date_x').value);
            params.set('event_date_y', document.getElementById('event_date_y').value);
            params.set('event_time_x', document.getElementById('event_time_x').value);
            params.set('event_time_y', document.getElementById('event_time_y').value);
            params.set('venue_x', document.getElementById('venue_x').value);
            params.set('venue_y', document.getElementById('venue_y').value);
            params.set('customer_x', document.getElementById('customer_x').value);
            params.set('customer_y', document.getElementById('customer_y').value);
            params.set('table_x', document.getElementById('table_x').value);
            params.set('table_y', document.getElementById('table_y').value);
            params.set('type_x', document.getElementById('type_x').value);
            params.set('type_y', document.getElementById('type_y').value);
            params.set('qr_x', document.getElementById('qr_x').value);
            params.set('qr_y', document.getElementById('qr_y').value);
            params.set('qr_size', document.getElementById('qr_size').value);
            params.set('code_y', document.getElementById('code_y').value);
            
            // Use a test ticket code (use an existing one from your database)
            const testTicketCode = 'RES0001-20G20A0T0K-8SL6X-A001'; // Change this to an actual ticket code
            
            window.open(`generate_ticket_image.php?ticket_code=${encodeURIComponent(testTicketCode)}&${params.toString()}`, '_blank');
        }
    </script>
</body>
</html>