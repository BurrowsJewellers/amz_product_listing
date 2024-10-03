document.getElementById('extractButton').addEventListener('click', function () {
    chrome.tabs.query({ active: true, currentWindow: true }, function (tabs) {
        let tab = tabs[0];
        if (tab.url.includes("pandora.net")) {
            document.getElementById('status').textContent = 'Extracting data...';
            chrome.tabs.sendMessage(tab.id, { action: "extractData" }, function (response) {
                if (chrome.runtime.lastError) {
                    document.getElementById('status').textContent = 'Error: ' + chrome.runtime.lastError.message;
                } else if (response) {
                    document.getElementById('status').textContent = 'Data extracted. Uploading...';
                    chrome.runtime.sendMessage({ action: "uploadData", data: response });
                } else {
                    document.getElementById('status').textContent = 'No data extracted.';
                }
            });
        } else {
            document.getElementById('status').textContent = 'This extension only works on *.pandora.net';
        }
    });
});