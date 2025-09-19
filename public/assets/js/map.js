// Map initialization function
function initializeMap(containerId, defaultLat, defaultLng, defaultZoom = 13) {
    const map = L.map(containerId).setView([defaultLat, defaultLng], defaultZoom);

    // Configure default icon path
    const defaultIcon = L.icon({
        iconUrl: '/assets/css/images/marker-icon.png',
        shadowUrl: '/assets/css/images/marker-shadow.png',
        iconSize: [25, 41],
        iconAnchor: [12, 41],
        popupAnchor: [1, -34],
        shadowSize: [41, 41]
    });
    L.Marker.prototype.options.icon = defaultIcon;

    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Add a draggable marker
    const marker = L.marker([defaultLat, defaultLng], {
        draggable: true
    }).addTo(map);

    // Handle map click events
    map.on('click', function(e) {
        const lat = e.latlng.lat;
        const lng = e.latlng.lng;

        // Update marker position
        marker.setLatLng([lat, lng]);

        // Update coordinates in form
        updateCoordinates(lat, lng);
    });

    // Handle marker drag events
    marker.on('dragend', function(e) {
        const lat = e.target.getLatLng().lat;
        const lng = e.target.getLatLng().lng;

        // Update coordinates in form
        updateCoordinates(lat, lng);
    });

    return map;
}

// Function to update coordinates in form fields
function updateCoordinates(lat, lng) {
    // Update city coordinates if on city page
    if (typeof window.updateCityCoordinates === 'function') {
        window.updateCityCoordinates(lat, lng);
    }

    // Update area coordinates if on area page
    if (typeof window.updateAreaCoordinates === 'function') {
        window.updateAreaCoordinates(lat, lng);
    }
}

// Function to set map view to specific coordinates
function setMapView(map, lat, lng, zoom = 13) {
    map.setView([lat, lng], zoom);
}

// Function to update marker position
function updateMarkerPosition(marker, lat, lng) {
    marker.setLatLng([lat, lng]);
}

// Export functions for use in other files
window.mapUtils = {
    initializeMap,
    updateCoordinates,
    setMapView,
    updateMarkerPosition,
    removeMap(containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;
        if (container._leaflet_id) {
            const map = L.map(containerId);
            map.remove();
        }

        // Optional: Clear inner HTML to fully reset the container
        container.innerHTML = '';
    }
};
