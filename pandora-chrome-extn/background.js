function showNotification(title, message) {
    chrome.notifications.create({
        type: 'basic',
        iconUrl: 'icon128.png',
        title: title,
        message: message
    });
}

chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    console.log('Received message in background script:', request);
    if (request.action === "uploadData") {
        fetch('https://portal.burrowsjewellers.com.au/api/upload-data', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(request.data)
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                console.log('Success:', data);
                showNotification('Success', 'Data successfully uploaded to the server.');
            })
            .catch((error) => {
                console.error('Error:', error);
                showNotification('Error', 'Failed to upload data to the server.');
            });
    }
});

console.log('Background script loaded');