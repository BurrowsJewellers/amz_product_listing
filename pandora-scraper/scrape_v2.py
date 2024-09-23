import time
import sys
from bs4 import BeautifulSoup
from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager
from datetime import datetime
import mysql.connector
from mysql.connector import Error
import json
import logging
import argparse
import traceback
import os
from dotenv import load_dotenv

def setup_logging():
    logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

def create_db_connection():
    try:
        logging.info("Attempting to load env...")
        env_path = '../.env'
        load_dotenv(dotenv_path=env_path)
        db_host=os.getenv('PYTHON_DB_HOST')
        db_user=os.getenv('PYTHON_DB_USERNAME')
        db_password=os.getenv('PYTHON_DB_PASSWORD')
        db_database=os.getenv('PYTHON_DB_DATABASE')

        logging.info("Env loaded...")
        logging.info("Attempting to connect to the database...")
        connection = mysql.connector.connect(
            host=db_host,
            user=db_user,
            password=db_password,
            database=db_database,
            auth_plugin='mysql_native_password'
        )
        logging.info(f"Connection object created: {type(connection)}")
        if connection.is_connected():
            logging.info("Successfully connected to the database")
            return connection
        else:
            logging.error("Connection created but not connected")
            return None
    except Error as e:
        logging.error(f"Error while connecting to MySQL: {e}")
        logging.error(f"Error details: {traceback.format_exc()}")
        return None

def setup_webdriver():
    try:
        chrome_options = Options()
        chrome_options.add_argument('--headless')
        chrome_options.add_argument('--no-sandbox')
        chrome_options.add_argument('--disable-dev-shm-usage')
        return webdriver.Chrome(service=Service(ChromeDriverManager().install()), options=chrome_options)
    except Exception as e:
        logging.error(f"Error setting up webdriver: {e}")
        logging.error(f"Error details: {traceback.format_exc()}")
        raise

def check_design_no_exists(connection, design_no):
    if not connection:
        logging.error("Database connection is not established.")
        return False
    try:
        cursor = connection.cursor()
        cursor.execute("SELECT COUNT(*) FROM `pandora_lists` WHERE `design_no` LIKE %s", (f"{design_no}%",))
        result = cursor.fetchone()
        cursor.close()
        return result[0] > 0
    except Error as e:
        logging.error(f"Error checking design number existence: {e}")
        logging.error(f"Error details: {traceback.format_exc()}")
        return False
    finally:
        if 'cursor' in locals() and cursor is not None:
            cursor.close()

def insert_new_record(connection, design_no):
    if not connection:
        logging.error("Database connection is not established.")
        return
    try:
        current_timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        cursor = connection.cursor()

        cursor.execute("""
            INSERT INTO `pandora_lists` 
            (`design_no`, `created_at`, `updated_at`) 
            VALUES (%s, %s, %s)
        """, (design_no, current_timestamp, current_timestamp))
        connection.commit()
        cursor.close()
        logging.info(f"New record inserted for design number: {design_no}")
    except Error as e:
        logging.error(f"Error inserting new record: {e}")
        logging.error(f"Error details: {traceback.format_exc()}")
        connection.rollback()

def update_record(connection, query, params):
    if not connection:
        logging.error("Database connection is not established.")
        return
    try:
        cursor = connection.cursor()
        cursor.execute(query, params)
        connection.commit()
        cursor.close()
        logging.info("Record updated successfully")
    except Error as e:
        logging.error(f"Error updating record: {e}")
        logging.error(f"Error details: {traceback.format_exc()}")
        connection.rollback()
    finally:
        if 'cursor' in locals() and cursor is not None:
            cursor.close()

def scrape(design_no):
    if not design_no:
        logging.error("Design number is empty or None.")
        return False

    setup_logging()
    connection = create_db_connection()
    if not connection:
        logging.error("Failed to establish database connection. Exiting.")
        return

    driver = None
    try:
        driver = setup_webdriver()
        logging.info("WebDriver set up successfully")
        
        exists = check_design_no_exists(connection, design_no)
        logging.info(f"Design number exists: {exists}")
        
        if not exists:
            insert_new_record(connection, design_no)
            logging.info("New record inserted")

        logging.info(f"Processing design number: {design_no}")
        pandora_url = f'https://au.pandora.net/on/demandware.store/Sites-en-AU-Site/en_AU/SearchServices-GetSuggestions?q={design_no}'

        driver.get(pandora_url)
        logging.info("Navigated to Pandora URL")
        
        soup = BeautifulSoup(driver.page_source, 'html.parser')
        search_response = soup.prettify()
        logging.info("Parsed page source")

        current_timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        logging.info("Updating search response")
        update_record(connection, 
                      "UPDATE `pandora_lists` SET `search_response` = %s, `updated_at` = %s WHERE `design_no` LIKE %s", 
                      (search_response, current_timestamp, f"{design_no}%"))

        product_div = soup.find('div', {'data-auto': 'divSearchSuggestionProduct'})
        if product_div:
            link = product_div.find('a')
            if link:
                href = link.get('href')
                update_record(connection, 
                              "UPDATE `pandora_lists` SET `product_url` = %s WHERE `design_no` LIKE %s", 
                              (href, f"{design_no}%"))
                logging.info(f"Found product URL: {href}")

                # Process product page
                driver.get(f"https://au.pandora.net/{href}")
                product_soup = BeautifulSoup(driver.page_source, 'html.parser')
                product_response = product_soup.prettify()

                # Extract image links
                # elements = product_soup.find_all(class_="d-block img-fluid js-product-image")
                # elements = product_soup.find_all(class_="js-product-image-carousel-cell")
                elements = product_soup.find_all("img", class_="js-product-image")

                links = []
                for element in elements:
                    data_img = element.get('data-img')
                    if data_img:
                        try:
                            data_img = data_img.replace("'", '"')
                            img_data = json.loads(data_img)
                            if 'hires' in img_data:
                                links.append(img_data['hires'])
                        except json.JSONDecodeError:
                            logging.warning(f"Could not parse JSON: {data_img}")

                links_json = json.dumps(links, indent=2)

                description_div = product_soup.find('div', class_='short-description', attrs={'data-auto': 'divProductDescription'})

                product_description = None
                # Extract the text from the div
                if description_div:
                    product_description = description_div.text

                update_record(connection, 
                              "UPDATE `pandora_lists` SET `product_response` = %s, `product_description` = %s, `images` = %s, `updated_at` = %s WHERE `design_no` LIKE %s", 
                              (product_response, product_description, links_json, current_timestamp, f"{design_no}%"))

        name_span = soup.find('span', {'data-auto': 'lblSearchProductName'})
        if name_span:
            product_name = name_span.text.strip()
            update_record(connection, 
                          "UPDATE `pandora_lists` SET `product_name` = %s WHERE `design_no` LIKE %s", 
                          (product_name, f"{design_no}%"))
            logging.info(f"Found product name: {product_name}")

        # Extract SKU if available
        sku_span = soup.find('span', {'data-auto': 'lblSearchProductSKU'})
        if sku_span:
            sku = sku_span.text.strip()
            update_record(connection, 
                          "UPDATE `pandora_lists` SET `sku` = %s WHERE `design_no` LIKE %s", 
                          (sku, f"{design_no}%"))
            logging.info(f"Found SKU: {sku}")

    except Exception as e:
        logging.error(f"An error occurred: {str(e)}")
        logging.error(f"Error details: {traceback.format_exc()}")
    finally:
        if connection:
            try:
                connection.close()
                logging.info("MySQL connection is closed")
            except Error as e:
                logging.error(f"Error closing MySQL connection: {e}")
        if driver:
            driver.quit()

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Scrape Pandora website for a given design number")
    parser.add_argument("design_no", help="The design number to scrape")
    args = parser.parse_args()

    scrape(args.design_no)