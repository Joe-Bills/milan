<?php include 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Map Test</title>
    <style>
        #map { height: 500px; width: 100%; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <h2>Google Maps Test</h2>
    <div id="map"></div>

    <script>
        function initMap() {
            console.log("Google Maps initialized successfully!");
            
            // Default location (Nairobi)
            const defaultLocation = { lat: -1.2921, lng: 36.8219 };
            
            const map = new google.maps.Map(document.getElementById("map"), {
                zoom: 12,
                center: defaultLocation,
            });

            // Add a test marker
            new google.maps.Marker({
                position: defaultLocation,
                map: map,
                title: "Test Location"
            });
        }

        function handleMapError() {
            console.error("Failed to load Google Maps");
            document.getElementById('map').innerHTML = 
                '<div style="color: red; padding: 20px; text-align: center;">' +
                '❌ Google Maps failed to load. Check your API key and console for errors.' +
                '</div>';
        }
    </script>
    
    <!-- Load Google Maps with error handling -->
    <script 
        async 
        defer 
        src="https://maps.googleapis.com/maps/api/js?key=YOUR_API_KEY_HERE&callback=initMap"
        onerror="handleMapError()">
    </script>
    
    <div style="margin-top: 20px;">
        <h3>Troubleshooting Steps:</h3>
        <ol>
            <li>Replace YOUR_API_KEY_HERE with your actual Google Maps API key</li>
            <li>Ensure the Maps JavaScript API is enabled in Google Cloud Console</li>
            <li>Check browser console for errors (F12 → Console)</li>
        </ol>
    </div>
</body>
</html>