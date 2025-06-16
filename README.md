# WooCommerce Taxonomy Import Tool

A WP-CLI based tool for importing and updating WooCommerce product taxonomies from a CSV file. This tool streamlines the process of managing taxonomy assignments for products, with robust validation, error handling, and detailed reporting.
Note: This project is a work in progress, and its functionality has not been fully tested.

## Features
### Core Functionality

Reads a CSV file containing SKU and taxonomy values.
Retrieves product IDs based on SKU.
Updates or replaces taxonomy terms for products.
Handles cases where products or taxonomies are not found.
Supports multiple taxonomy terms and detects duplicates.
Generates detailed statistics and logs results to a file with a timestamp.

### CSV Validation

Validates CSV structure (consistent column count, empty rows/cells).
Checks for duplicate SKUs in the file.
Ensures UTF-8 encoding.
Handles missing or extra cells in rows.

### Error Handling

Stops execution on critical database errors, reporting the problematic product.
Notifies users if the log file cannot be created.
Skips non-existent products or taxonomies with appropriate notifications.

### Configuration Options

-verbose: Enables detailed output in the terminal.
Custom CSV delimiter: Supports , or ; as delimiters.
Skip rows: Allows skipping a specified number of rows at the file's start.
-dry-run: Simulates execution without making changes to the database.

### Advanced Statistics

Tracks processing time and average speed (rows/second).
Reports the number of processed terms per taxonomy.
Displays a progress bar for large files.

### Safety Features

Checks file size to prevent processing excessively large files.
Supports batch processing with configurable batch sizes.
Limits the number of rows processed at once.

### Post-Processing

Clears WordPress cache after bulk updates.
Updates taxonomy term counters automatically.
Supports optional backup before making changes.

## Program Structure

The tool is modular for easy maintenance and updates:

### Initialization and Checks

Validates input parameters and flags.
Ensures log file writeability.
Confirms WordPress and WooCommerce availability.
Initializes statistics and error tracking.


### CSV Validation

Verifies file existence, accessibility, and UTF-8 encoding.
Checks file size and CSV structure.
Detects duplicate SKUs and handles empty rows/cells.


### Data Preparation

Parses CSV into a structured array.
Validates taxonomies, terms, and product SKUs.
Prepares data for processing or dry-run mode.


### Database Updates

Retrieves product IDs by SKU.
Updates or replaces taxonomy terms.
Handles database errors and collects operation statistics.


### Completion and Reporting

Outputs final statistics to the terminal.
Generates a detailed log file with a timestamp.
Clears cache and updates term counters if needed.



## Output Log File
The tool generates a log file named taxonomy_update_results_YYYY-MM-DD_HH-MM-SS.log with the following structure:
=== TAXONOMY UPDATE RESULTS ===
Date: 2025-06-16 14:30:25
CSV File: export2.v6.csv
Mode: [UPDATE/REPLACE]

STATISTICS:
- Total rows processed: 4
- Products found: 3
- Products not found: 1
- Taxonomies updated: 6
- Taxonomies skipped (already existed): 2

ERRORS:
Products not found:
- S-99999

Terms not found:
- stone_type: "Unknown Stone"

Duplicate terms found:
- size_mm: "50mm" (IDs: 123, 456)

Already existing taxonomies (skipped):
- S-00816: stone_type "Agate"
- S-01041: size_mm "50–54 mm (≈1.93–2.13 inch)"

## Installation

Ensure WP-CLI and WooCommerce are installed.
Clone this repository:git clone https://github.com/yourusername/woocommerce-taxonomy-import.git

Navigate to the project directory and install dependencies (if any).

### Usage
Run the tool using WP-CLI:
wp taxonomy-import --file=export2.v6.csv --delimiter="," --verbose --dry-run

### Options

--file: Path to the CSV file.
--delimiter: CSV delimiter (, or ;).
--verbose: Enable detailed output.
--dry-run: Simulate execution without database changes.
--skip-rows=<number>: Skip the specified number of rows at the start.
--batch-size=<number>: Number of rows to process per batch.

### Requirements

WordPress with WooCommerce installed.
WP-CLI.
PHP 7.4 or higher.

## License
GNU General Public License v3.0
Contributing
Contributions are welcome! Please submit issues or pull requests to improve the tool. Note that all contributions must be licensed under the GNU GPL v3.0 to ensure the project remains fully open-source.
