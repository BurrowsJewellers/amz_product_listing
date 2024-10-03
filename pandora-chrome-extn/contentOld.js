function extractData() {
    console.log('Extracting data from page...');
    const productUrl = document.querySelector('link[rel="canonical"]')?.href || '';
    // const designNo = document.querySelector('[data-bv-show="rating_summary"]')?.getAttribute('data-bv-productid') || '';
    const designNo = document.querySelector('.product-id')?.textContent.trim() || '';
    const productName = document.querySelector('.product-name.title-medium')?.textContent || '';
    const productDescription = document.querySelector('.short-description')?.textContent || '';

    const imageLinks = [];
    const elements = document.querySelectorAll('[data-img]');
    elements.forEach(element => {
        const dataImg = element.getAttribute('data-img');
        if (dataImg) {
            try {
                const imgData = JSON.parse(dataImg.replace(/'/g, '"'));
                if (imgData.hires) {
                    imageLinks.push(imgData.hires);
                }
            } catch (error) {
                console.warn("Could not parse JSON:", dataImg);
            }
        }
    });

    console.log('Extracted data:', { productUrl, designNo, productName, productDescription, imageLinks });
    return {
        product_url: productUrl,
        design_no: designNo,
        product_name: productName,
        product_description: productDescription,
        image_links: imageLinks
    };
}

chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    console.log('Received message in content script:', request);
    if (request.action === "extractData") {
        const data = extractData();
        sendResponse(data);
    }
    return true;  // Indicates that the response is sent asynchronously
});
