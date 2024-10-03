async function fetchImageAsBase64(url) {
    try {
        const response = await fetch(url);
        const blob = await response.blob();
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onloadend = () => resolve(reader.result.split(',')[1]);
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    } catch (error) {
        console.error('Error fetching image:', error);
        return null;
    }
}

async function extractData() {
    console.log('Extracting data from page...');
    const productUrl = document.querySelector('link[rel="canonical"]')?.href || '';
    // const designNo = document.querySelector('[data-bv-show="rating_summary"]')?.getAttribute('data-bv-productid') || '';
    const designNo = document.querySelector('.product-id')?.textContent.trim() || '';
    const productName = document.querySelector('.product-name.title-medium')?.textContent || '';
    const productDescription = document.querySelector('.short-description')?.textContent || '';

    const imageContents = [];
    const elements = document.querySelectorAll('[data-img]');
    for (const element of elements) {
        const dataImg = element.getAttribute('data-img');
        if (dataImg) {
            try {
                const imgData = JSON.parse(dataImg.replace(/'/g, '"'));
                if (imgData.hires) {
                    const base64Image = await fetchImageAsBase64(imgData.hires);
                    if (base64Image) {
                        imageContents.push(base64Image);
                    }
                }
            } catch (error) {
                console.warn("Could not parse JSON:", dataImg);
            }
        }
    }

    console.log('Extracted data:', { productUrl, designNo, productName, productDescription, imageCount: imageContents.length });
    return {
        product_url: productUrl,
        design_no: designNo,
        product_name: productName,
        product_description: productDescription,
        image_contents: imageContents
    };
}

chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    console.log('Received message in content script:', request);
    if (request.action === "extractData") {
        extractData().then(data => sendResponse(data));
        return true;  // Indicates that the response is sent asynchronously
    }
});