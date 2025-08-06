<?php

/**
 * Plugin Name: WooCommerce Bulk Delete Products
 * Description: Delete all WooCommerce products in batches with progress tracking including images and variations
 * Version: 1.0.0
 * Author: Yasir Shabbir
 * Author URI: https://yasirshabbir.com
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WooCommerce_Bulk_Delete_Products
{

    private $batch_size = 30; // Process 30 products at a time (fewer than orders due to complexity)
    private $log_file;

    public function __construct()
    {
        $this->log_file = WP_CONTENT_DIR . '/wc-bulk-delete-products-log.txt';

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_delete_products_batch', array($this, 'delete_products_batch'));
        add_action('wp_ajax_get_products_count', array($this, 'get_products_count'));
        add_action('wp_ajax_get_product_types', array($this, 'get_product_types'));
        add_action('wp_ajax_get_product_categories', array($this, 'get_product_categories'));
        add_action('wp_ajax_get_product_tags', array($this, 'get_product_tags'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function add_admin_menu()
    {
        // Add to WooCommerce menu if it exists, otherwise add to Tools menu
        if (class_exists('WooCommerce') || function_exists('WC')) {
            add_submenu_page(
                'woocommerce',
                'Bulk Delete Products',
                'Bulk Delete Products',
                'manage_woocommerce',
                'wc-bulk-delete-products',
                array($this, 'admin_page')
            );
        } else {
            add_submenu_page(
                'tools.php',
                'Bulk Delete Products',
                'Bulk Delete Products',
                'manage_options',
                'wc-bulk-delete-products',
                array($this, 'admin_page')
            );
        }
    }

    public function enqueue_scripts($hook)
    {
        if ($hook !== 'woocommerce_page_wc-bulk-delete-products' && $hook !== 'tools_page_wc-bulk-delete-products') {
            return;
        }

        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'wcBulkDeleteProducts', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_bulk_delete_products_nonce')
        ));
    }

    public function admin_page()
    {
?>
<div class="wrap yasir-bulk-delete-wrap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    @import url('https://fonts.googleapis.com/css2?family=Lato:wght@300;400;700&display=swap');

    .yasir-bulk-delete-wrap {
        font-family: 'Lato', sans-serif;
        background: #121212;
        color: #ffffff;
        padding: 20px;
        border-radius: 3px;
        margin: 20px 0;
    }

    .yasir-header {
        background: linear-gradient(135deg, #1e1e1e, #2a2a2a);
        padding: 30px;
        border-radius: 3px;
        border: 1px solid #333333;
        margin-bottom: 30px;
        text-align: center;
    }

    .yasir-header h1 {
        color: #16e791;
        font-size: 2.5em;
        margin: 0 0 10px 0;
        font-weight: 700;
    }

    .yasir-header .subtitle {
        color: #e0e0e0;
        font-size: 1.1em;
        margin: 0;
    }

    .yasir-header .brand-link {
        color: #16e791;
        text-decoration: none;
        font-weight: 400;
    }

    .yasir-header .brand-link:hover {
        text-decoration: underline;
    }

    .yasir-card {
        background: #1e1e1e;
        border: 1px solid #333333;
        border-radius: 3px;
        padding: 30px;
        margin-bottom: 20px;
    }

    .yasir-card h2 {
        color: #16e791;
        margin-top: 0;
        font-size: 1.5em;
    }

    .yasir-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .yasir-stat-card {
        background: #2a2a2a;
        border: 1px solid #444444;
        border-radius: 3px;
        padding: 20px;
        text-align: center;
    }

    .yasir-stat-number {
        font-size: 2em;
        font-weight: 700;
        color: #16e791;
        margin: 0 0 5px 0;
    }

    .yasir-stat-label {
        color: #e0e0e0;
        font-size: 0.9em;
        margin: 0;
    }

    .yasir-button {
        background: #16e791;
        color: #121212;
        border: none;
        padding: 15px 30px;
        border-radius: 3px;
        font-size: 1.1em;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s ease;
        font-family: 'Lato', sans-serif;
        margin-right: 10px;
    }

    .yasir-button:hover {
        background: #14d182;
        transform: translateY(-2px);
    }

    .yasir-button:disabled {
        background: #6c757d;
        color: #e0e0e0;
        cursor: not-allowed;
        transform: none;
    }

    .yasir-button.danger {
        background: #dc3545;
        color: #ffffff;
    }

    .yasir-button.danger:hover {
        background: #c82333;
    }

    .yasir-progress-container {
        margin: 20px 0;
        display: none;
    }

    .yasir-progress-bar {
        width: 100%;
        height: 20px;
        background: #2a2a2a;
        border-radius: 3px;
        overflow: hidden;
        border: 1px solid #444444;
    }

    .yasir-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #16e791, #14d182);
        width: 0%;
        transition: width 0.3s ease;
    }

    .yasir-progress-text {
        text-align: center;
        margin-top: 10px;
        color: #e0e0e0;
    }

    .yasir-log {
        background: #121212;
        border: 1px solid #333333;
        border-radius: 3px;
        padding: 20px;
        max-height: 300px;
        overflow-y: auto;
        font-family: 'Courier New', monospace;
        font-size: 0.9em;
        color: #e0e0e0;
        white-space: pre-wrap;
    }

    .yasir-warning {
        background: #ffc107;
        color: #121212;
        padding: 15px;
        border-radius: 3px;
        margin: 20px 0;
        font-weight: 700;
    }

    .yasir-success {
        background: #28a745;
        color: #ffffff;
        padding: 15px;
        border-radius: 3px;
        margin: 20px 0;
        font-weight: 700;
    }

    .yasir-error {
        background: #dc3545;
        color: #ffffff;
        padding: 15px;
        border-radius: 3px;
        margin: 20px 0;
        font-weight: 700;
    }

    .yasir-info {
        background: #17a2b8;
        color: #ffffff;
        padding: 15px;
        border-radius: 3px;
        margin: 20px 0;
        font-weight: 700;
    }

    .deletion-options {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }

    .product-type-options {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px;
        margin-top: 15px;
    }

    #product-types-list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 8px;
        margin-top: 10px;
    }

    .product-type-option {
        background: #2a2a2a;
        border: 1px solid #444444;
        border-radius: 3px;
        padding: 8px 12px;
    }

    .product-type-option label {
        color: #e0e0e0;
        font-size: 0.9em;
        cursor: pointer;
        display: flex;
        align-items: center;
        margin: 0;
    }

    .product-type-option input[type="checkbox"] {
        margin-right: 8px;
    }

    /* Enhanced Filter Sections */
    .yasir-filter-section {
        background: #1e1e1e;
        border: 1px solid #333333;
        border-radius: 3px;
        padding: 20px;
        margin-bottom: 20px;
    }

    .yasir-filter-header {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
        cursor: pointer;
        user-select: none;
    }

    .yasir-filter-header i {
        color: #16e791;
        margin-right: 10px;
        font-size: 1.2em;
        width: 20px;
        text-align: center;
    }

    .yasir-filter-header h3 {
        color: #16e791;
        margin: 0;
        font-size: 1.2em;
        flex: 1;
    }

    .yasir-filter-toggle {
        color: #e0e0e0;
        font-size: 1em;
        transition: transform 0.3s ease;
    }

    .yasir-filter-toggle.collapsed {
        transform: rotate(-90deg);
    }

    .yasir-filter-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin-top: 15px;
    }

    .yasir-filter-content.collapsed {
        display: none;
    }

    .yasir-filter-group {
        background: #2a2a2a;
        border: 1px solid #444444;
        border-radius: 3px;
        padding: 15px;
    }

    .yasir-filter-group h4 {
        color: #16e791;
        margin: 0 0 10px 0;
        font-size: 1em;
        display: flex;
        align-items: center;
    }

    .yasir-filter-group h4 i {
        margin-right: 8px;
        width: 16px;
        text-align: center;
    }

    .yasir-checkbox-group {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 8px;
        margin-top: 10px;
    }

    .yasir-checkbox-item {
        display: flex;
        align-items: center;
        padding: 5px 0;
    }

    .yasir-checkbox-item input[type="checkbox"] {
        margin-right: 8px;
        accent-color: #16e791;
    }

    .yasir-checkbox-item label {
        color: #e0e0e0;
        font-size: 0.9em;
        cursor: pointer;
        margin: 0;
        display: flex;
        align-items: center;
    }

    .yasir-input-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .yasir-input-row {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .yasir-input-group label {
        color: #e0e0e0;
        font-size: 0.9em;
        margin-bottom: 5px;
        display: flex;
        align-items: center;
    }

    .yasir-input-group label i {
        margin-right: 6px;
        color: #16e791;
        width: 14px;
        text-align: center;
    }

    .yasir-input-group input,
    .yasir-input-group select {
        background: #121212;
        border: 1px solid #444444;
        border-radius: 3px;
        padding: 8px 12px;
        color: #e0e0e0;
        font-family: 'Lato', sans-serif;
        font-size: 0.9em;
        flex: 1;
    }

    .yasir-input-group input:focus,
    .yasir-input-group select:focus {
        outline: none;
        border-color: #16e791;
        box-shadow: 0 0 0 2px rgba(22, 231, 145, 0.2);
    }

    .yasir-date-range {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 10px;
        align-items: center;
    }

    .yasir-date-range span {
        color: #e0e0e0;
        text-align: center;
        font-size: 0.9em;
    }

    .yasir-price-range {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 10px;
        align-items: center;
    }

    .yasir-price-range span {
        color: #e0e0e0;
        text-align: center;
        font-size: 0.9em;
    }

    .yasir-select-all {
        background: #2a2a2a;
        border: 1px solid #444444;
        border-radius: 3px;
        padding: 10px 15px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
    }

    .yasir-select-all input[type="checkbox"] {
        margin-right: 10px;
        accent-color: #16e791;
    }

    .yasir-select-all label {
        color: #16e791;
        font-weight: 600;
        cursor: pointer;
        margin: 0;
        display: flex;
        align-items: center;
    }

    .yasir-select-all label i {
        margin-right: 8px;
    }

    .yasir-filter-summary {
        background: #2a2a2a;
        border: 1px solid #444444;
        border-radius: 3px;
        padding: 15px;
        margin: 20px 0;
        display: none;
    }

    .yasir-filter-summary.active {
        display: block;
    }

    .yasir-filter-summary h4 {
        color: #16e791;
        margin: 0 0 10px 0;
        display: flex;
        align-items: center;
    }

    .yasir-filter-summary h4 i {
        margin-right: 8px;
    }

    .yasir-filter-tags {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .yasir-filter-tag {
        background: #16e791;
        color: #121212;
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 0.8em;
        font-weight: 600;
        display: flex;
        align-items: center;
    }

    .yasir-filter-tag i {
        margin-left: 6px;
        cursor: pointer;
    }

    .yasir-advanced-toggle {
        background: #2a2a2a;
        border: 1px solid #444444;
        border-radius: 3px;
        padding: 12px 15px;
        margin: 20px 0;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .yasir-advanced-toggle:hover {
        background: #333333;
        border-color: #16e791;
    }

    .yasir-advanced-toggle span {
        color: #16e791;
        font-weight: 600;
        display: flex;
        align-items: center;
    }

    .yasir-advanced-toggle span i {
        margin-right: 8px;
    }

    .yasir-advanced-toggle .toggle-icon {
        color: #e0e0e0;
        transition: transform 0.3s ease;
    }

    .yasir-advanced-toggle.active .toggle-icon {
        transform: rotate(180deg);
    }

    .product-type-options {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }

    #product-types-list {
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
    }

    .option-card {
        background: #2a2a2a;
        border: 1px solid #444444;
        border-radius: 3px;
        padding: 15px;
    }

    .option-card label {
        display: flex;
        align-items: center;
        color: #e0e0e0;
        font-weight: 600;
        cursor: pointer;
    }

    .option-card input[type="checkbox"] {
        margin-right: 10px;
        transform: scale(1.2);
        accent-color: #16e791;
    }

    .option-description {
        font-size: 0.9em;
        color: #b0b0b0;
        margin-top: 5px;
        margin-left: 25px;
    }

    /* Loading spinner styles */
    .yasir-loading-spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid #ffffff;
        border-radius: 50%;
        border-top-color: transparent;
        animation: yasir-spin 1s ease-in-out infinite;
        margin-right: 8px;
    }

    @keyframes yasir-spin {
        to {
            transform: rotate(360deg);
        }
    }

    .yasir-button.loading {
        opacity: 0.7;
        cursor: not-allowed;
        pointer-events: none;
    }

    .yasir-stats.loading .yasir-stat-number {
        opacity: 0.5;
        position: relative;
        transition: opacity 0.3s ease;
    }

    .yasir-stats.loading .yasir-stat-number::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 16px;
        height: 16px;
        margin: -8px 0 0 -8px;
        border: 2px solid #16e791;
        border-radius: 50%;
        border-top-color: transparent;
        animation: yasir-spin 1s ease-in-out infinite;
    }

    .yasir-stat-number {
        transition: opacity 0.3s ease;
    }
    </style>

    <div class="yasir-header">
        <h1>WooCommerce Bulk Delete Products</h1>
        <p class="subtitle">Developed by <a href="https://yasirshabbir.com" class="brand-link" target="_blank">Yasir
                Shabbir</a></p>
    </div>

    <div class="yasir-card">
        <h2>Product Statistics</h2>
        <div class="yasir-stats">
            <div class="yasir-stat-card">
                <div class="yasir-stat-number" id="total-products">Loading...</div>
                <div class="yasir-stat-label">Total Products</div>
            </div>
            <div class="yasir-stat-card">
                <div class="yasir-stat-number" id="total-variations">Loading...</div>
                <div class="yasir-stat-label">Product Variations</div>
            </div>
            <div class="yasir-stat-card">
                <div class="yasir-stat-number" id="processed-products">0</div>
                <div class="yasir-stat-label">Processed</div>
            </div>
            <div class="yasir-stat-card">
                <div class="yasir-stat-number" id="remaining-products">Loading...</div>
                <div class="yasir-stat-label">Remaining</div>
            </div>
        </div>
    </div>

    <div class="yasir-card">
        <h2>Product Type Filter</h2>
        <div class="yasir-info">
            🎯 Select which product types to delete. Leave all unchecked to delete all product types.
        </div>

        <div class="product-type-options" id="product-type-options">
            <div class="option-card">
                <label>
                    <input type="checkbox" id="select-all-types">
                    Select All Product Types
                </label>
                <div class="option-description">Check/uncheck all product types at once</div>
            </div>
            <div id="product-types-list">
                <!-- Product types will be loaded dynamically -->
                <div class="option-card">
                    <div class="yasir-loading-spinner" style="margin: 10px auto; display: block;"></div>
                    <div style="text-align: center; color: #b0b0b0;">Loading product types...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Advanced Filters Toggle -->
    <div class="yasir-advanced-toggle" id="advanced-filters-toggle">
        <span><i class="fas fa-filter"></i>Advanced Filtering Options</span>
        <i class="fas fa-chevron-down toggle-icon"></i>
    </div>

    <!-- Advanced Filters Container -->
    <div id="advanced-filters-container" style="display: none;">
        
        <!-- Filter Summary -->
        <div class="yasir-filter-summary" id="filter-summary">
            <h4><i class="fas fa-tags"></i>Active Filters</h4>
            <div class="yasir-filter-tags" id="filter-tags"></div>
        </div>

        <!-- Product Status & Visibility -->
        <div class="yasir-filter-section">
            <div class="yasir-filter-header" data-target="status-visibility">
                <i class="fas fa-eye"></i>
                <h3>Product Status & Visibility</h3>
                <i class="fas fa-chevron-down yasir-filter-toggle"></i>
            </div>
            <div class="yasir-filter-content" id="status-visibility">
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-circle"></i>Product Status</h4>
                    <div class="yasir-select-all">
                        <input type="checkbox" id="select-all-status">
                        <label for="select-all-status"><i class="fas fa-check-double"></i>Select All Status</label>
                    </div>
                    <div class="yasir-checkbox-group">
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="status-publish" name="product_status[]" value="publish">
                            <label for="status-publish">Published</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="status-draft" name="product_status[]" value="draft">
                            <label for="status-draft">Draft</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="status-private" name="product_status[]" value="private">
                            <label for="status-private">Private</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="status-pending" name="product_status[]" value="pending">
                            <label for="status-pending">Pending Review</label>
                        </div>
                    </div>
                </div>
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-low-vision"></i>Visibility</h4>
                    <div class="yasir-checkbox-group">
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="visibility-visible" name="product_visibility[]" value="visible">
                            <label for="visibility-visible">Catalog & Search</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="visibility-catalog" name="product_visibility[]" value="catalog">
                            <label for="visibility-catalog">Catalog Only</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="visibility-search" name="product_visibility[]" value="search">
                            <label for="visibility-search">Search Only</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="visibility-hidden" name="product_visibility[]" value="hidden">
                            <label for="visibility-hidden">Hidden</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="featured-products" name="product_visibility[]" value="featured">
                            <label for="featured-products">Featured Products</label>
                        </div>
                    </div>
                </div>
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-boxes"></i>Stock Status</h4>
                    <div class="yasir-checkbox-group">
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="stock-instock" name="stock_status[]" value="instock">
                            <label for="stock-instock">In Stock</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="stock-outofstock" name="stock_status[]" value="outofstock">
                            <label for="stock-outofstock">Out of Stock</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="stock-onbackorder" name="stock_status[]" value="onbackorder">
                            <label for="stock-onbackorder">On Backorder</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Category & Taxonomy -->
        <div class="yasir-filter-section">
            <div class="yasir-filter-header" data-target="category-taxonomy">
                <i class="fas fa-sitemap"></i>
                <h3>Category & Taxonomy</h3>
                <i class="fas fa-chevron-down yasir-filter-toggle"></i>
            </div>
            <div class="yasir-filter-content collapsed" id="category-taxonomy">
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-folder"></i>Product Categories</h4>
                    <div class="yasir-input-group">
                        <label><i class="fas fa-search"></i>Search Categories</label>
                        <input type="text" id="category-search" placeholder="Type to search categories...">
                    </div>
                    <div class="yasir-checkbox-group" id="categories-list">
                        <div style="text-align: center; color: #b0b0b0; padding: 20px;">
                            <i class="fas fa-spinner fa-spin"></i> Loading categories...
                        </div>
                    </div>
                    <div class="yasir-checkbox-item">
                        <input type="checkbox" id="uncategorized-products" name="category_filter[]" value="uncategorized">
                        <label for="uncategorized-products">Uncategorized Products</label>
                    </div>
                </div>
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-tags"></i>Product Tags</h4>
                    <div class="yasir-input-group">
                        <label><i class="fas fa-search"></i>Search Tags</label>
                        <input type="text" id="tag-search" placeholder="Type to search tags...">
                    </div>
                    <div class="yasir-checkbox-group" id="tags-list">
                        <div style="text-align: center; color: #b0b0b0; padding: 20px;">
                            <i class="fas fa-spinner fa-spin"></i> Loading tags...
                        </div>
                    </div>
                    <div class="yasir-checkbox-item">
                        <input type="checkbox" id="untagged-products" name="tag_filter[]" value="untagged">
                        <label for="untagged-products">Products Without Tags</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pricing & Sales -->
        <div class="yasir-filter-section">
            <div class="yasir-filter-header" data-target="pricing-sales">
                <i class="fas fa-dollar-sign"></i>
                <h3>Pricing & Sales</h3>
                <i class="fas fa-chevron-down yasir-filter-toggle"></i>
            </div>
            <div class="yasir-filter-content collapsed" id="pricing-sales">
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-money-bill-wave"></i>Price Range</h4>
                    <div class="yasir-price-range">
                        <div class="yasir-input-group">
                            <label><i class="fas fa-dollar-sign"></i>Min Price</label>
                            <input type="number" id="min-price" name="min_price" placeholder="0.00" step="0.01" min="0">
                        </div>
                        <span>to</span>
                        <div class="yasir-input-group">
                            <label><i class="fas fa-dollar-sign"></i>Max Price</label>
                            <input type="number" id="max-price" name="max_price" placeholder="999999.99" step="0.01" min="0">
                        </div>
                    </div>
                </div>
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-percentage"></i>Sale Products</h4>
                    <div class="yasir-checkbox-group">
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="on-sale" name="sale_filter[]" value="on_sale">
                            <label for="on-sale">Products on Sale</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="not-on-sale" name="sale_filter[]" value="not_on_sale">
                            <label for="not-on-sale">Products not on Sale</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="zero-price" name="sale_filter[]" value="zero_price">
                            <label for="zero-price">Free Products (Zero Price)</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="no-price" name="sale_filter[]" value="no_price">
                            <label for="no-price">Products Without Price</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Date-Based Filters -->
        <div class="yasir-filter-section">
            <div class="yasir-filter-header" data-target="date-filters">
                <i class="fas fa-calendar-alt"></i>
                <h3>Date-Based Filters</h3>
                <i class="fas fa-chevron-down yasir-filter-toggle"></i>
            </div>
            <div class="yasir-filter-content collapsed" id="date-filters">
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-calendar-plus"></i>Creation Date</h4>
                    <div class="yasir-date-range">
                        <div class="yasir-input-group">
                            <label><i class="fas fa-calendar"></i>From Date</label>
                            <input type="date" id="created-from" name="created_from">
                        </div>
                        <span>to</span>
                        <div class="yasir-input-group">
                            <label><i class="fas fa-calendar"></i>To Date</label>
                            <input type="date" id="created-to" name="created_to">
                        </div>
                    </div>
                </div>
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-calendar-check"></i>Last Modified</h4>
                    <div class="yasir-date-range">
                        <div class="yasir-input-group">
                            <label><i class="fas fa-calendar"></i>From Date</label>
                            <input type="date" id="modified-from" name="modified_from">
                        </div>
                        <span>to</span>
                        <div class="yasir-input-group">
                            <label><i class="fas fa-calendar"></i>To Date</label>
                            <input type="date" id="modified-to" name="modified_to">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory & SKU -->
        <div class="yasir-filter-section">
            <div class="yasir-filter-header" data-target="inventory-sku">
                <i class="fas fa-warehouse"></i>
                <h3>Inventory & SKU</h3>
                <i class="fas fa-chevron-down yasir-filter-toggle"></i>
            </div>
            <div class="yasir-filter-content collapsed" id="inventory-sku">
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-barcode"></i>SKU Filters</h4>
                    <div class="yasir-input-group">
                        <label><i class="fas fa-search"></i>SKU Pattern</label>
                        <input type="text" id="sku-pattern" name="sku_pattern" placeholder="e.g., ABC-*, *-123, exact-sku">
                    </div>
                    <div class="yasir-checkbox-item">
                        <input type="checkbox" id="without-sku" name="sku_filter[]" value="without_sku">
                        <label for="without-sku">Products Without SKU</label>
                    </div>
                </div>
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-cubes"></i>Stock Quantity</h4>
                    <div class="yasir-input-row">
                        <div class="yasir-input-group">
                            <label><i class="fas fa-less-than"></i>Min Quantity</label>
                            <input type="number" id="min-stock" name="min_stock" placeholder="0" min="0">
                        </div>
                        <div class="yasir-input-group">
                            <label><i class="fas fa-greater-than"></i>Max Quantity</label>
                            <input type="number" id="max-stock" name="max_stock" placeholder="999999" min="0">
                        </div>
                    </div>
                </div>
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-weight"></i>Physical Properties</h4>
                    <div class="yasir-input-row">
                        <div class="yasir-input-group">
                            <label><i class="fas fa-weight-hanging"></i>Weight Range</label>
                            <input type="number" id="min-weight" name="min_weight" placeholder="Min weight" step="0.01" min="0">
                        </div>
                        <div class="yasir-input-group">
                            <label><i class="fas fa-weight-hanging"></i>Max Weight</label>
                            <input type="number" id="max-weight" name="max_weight" placeholder="Max weight" step="0.01" min="0">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sales & Performance -->
        <div class="yasir-filter-section">
            <div class="yasir-filter-header" data-target="sales-performance">
                <i class="fas fa-chart-line"></i>
                <h3>Sales & Performance</h3>
                <i class="fas fa-chevron-down yasir-filter-toggle"></i>
            </div>
            <div class="yasir-filter-content collapsed" id="sales-performance">
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-shopping-cart"></i>Sales Count</h4>
                    <div class="yasir-input-row">
                        <div class="yasir-input-group">
                            <label><i class="fas fa-chart-bar"></i>Min Sales</label>
                            <input type="number" id="min-sales" name="min_sales" placeholder="0" min="0">
                        </div>
                        <div class="yasir-input-group">
                            <label><i class="fas fa-chart-bar"></i>Max Sales</label>
                            <input type="number" id="max-sales" name="max_sales" placeholder="999999" min="0">
                        </div>
                    </div>
                    <div class="yasir-checkbox-item">
                        <input type="checkbox" id="zero-sales" name="sales_filter[]" value="zero_sales">
                        <label for="zero-sales">Products with Zero Sales</label>
                    </div>
                </div>
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-star"></i>Reviews & Ratings</h4>
                    <div class="yasir-input-group">
                        <label><i class="fas fa-star-half-alt"></i>Minimum Rating</label>
                        <select id="min-rating" name="min_rating">
                            <option value="">Any Rating</option>
                            <option value="1">1 Star & Above</option>
                            <option value="2">2 Stars & Above</option>
                            <option value="3">3 Stars & Above</option>
                            <option value="4">4 Stars & Above</option>
                            <option value="5">5 Stars Only</option>
                        </select>
                    </div>
                    <div class="yasir-checkbox-group">
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="no-reviews" name="review_filter[]" value="no_reviews">
                            <label for="no-reviews">Products Without Reviews</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="low-reviews" name="review_filter[]" value="low_reviews">
                            <label for="low-reviews">Products with Few Reviews (&lt; 5)</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Advanced Options -->
        <div class="yasir-filter-section">
            <div class="yasir-filter-header" data-target="advanced-options">
                <i class="fas fa-cogs"></i>
                <h3>Advanced Options</h3>
                <i class="fas fa-chevron-down yasir-filter-toggle"></i>
            </div>
            <div class="yasir-filter-content collapsed" id="advanced-options">
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-copy"></i>Duplicate Detection</h4>
                    <div class="yasir-checkbox-group">
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="duplicate-title" name="duplicate_filter[]" value="title">
                            <label for="duplicate-title">Duplicate Titles</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="duplicate-sku" name="duplicate_filter[]" value="sku">
                            <label for="duplicate-sku">Duplicate SKUs</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="duplicate-content" name="duplicate_filter[]" value="content">
                            <label for="duplicate-content">Duplicate Content</label>
                        </div>
                    </div>
                </div>
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-unlink"></i>Orphaned Products</h4>
                    <div class="yasir-checkbox-group">
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="orphaned-products" name="advanced_filter[]" value="orphaned">
                            <label for="orphaned-products">Products Without Categories</label>
                        </div>
                        <div class="yasir-checkbox-item">
                            <input type="checkbox" id="orphaned-images" name="advanced_filter[]" value="orphaned_images">
                            <label for="orphaned-images">Products with Missing Images</label>
                        </div>
                    </div>
                </div>
                <div class="yasir-filter-group">
                    <h4><i class="fas fa-user"></i>Author & Vendor</h4>
                    <div class="yasir-input-group">
                        <label><i class="fas fa-user-circle"></i>Author ID</label>
                        <input type="number" id="author-id" name="author_id" placeholder="Enter author ID" min="1">
                    </div>
                    <div class="yasir-input-group">
                        <label><i class="fas fa-users"></i>User Role</label>
                        <select id="user-role" name="user_role">
                            <option value="">Any Role</option>
                            <option value="administrator">Administrator</option>
                            <option value="editor">Editor</option>
                            <option value="author">Author</option>
                            <option value="contributor">Contributor</option>
                            <option value="shop_manager">Shop Manager</option>
                            <option value="customer">Customer</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="yasir-card">
        <h2>Deletion Options</h2>
        <div class="yasir-info">
            📋 Configure what data should be deleted along with the products. All options are recommended for complete
            cleanup.
        </div>

        <div class="deletion-options">
            <div class="option-card">
                <label>
                    <input type="checkbox" id="delete-images" checked>
                    Delete Product Images
                </label>
                <div class="option-description">Remove all product images from media library</div>
            </div>
            <div class="option-card">
                <label>
                    <input type="checkbox" id="delete-variations" checked>
                    Delete Product Variations
                </label>
                <div class="option-description">Remove all product variations and their data</div>
            </div>
            <div class="option-card">
                <label>
                    <input type="checkbox" id="delete-meta" checked>
                    Delete Product Meta
                </label>
                <div class="option-description">Remove all custom fields and meta data</div>
            </div>
            <div class="option-card">
                <label>
                    <input type="checkbox" id="delete-terms" checked>
                    Delete Product Terms
                </label>
                <div class="option-description">Remove categories, tags, and attributes relationships</div>
            </div>
            <div class="option-card">
                <label>
                    <input type="checkbox" id="only-without-sku">
                    Delete Only Products Without SKU
                </label>
                <div class="option-description">Only delete products that have empty or missing SKU values</div>
            </div>
        </div>
    </div>

    <div class="yasir-card">
        <h2>Bulk Delete Operations</h2>
        <div class="yasir-warning" id="warning-message">
            ⚠️ WARNING: This action will permanently delete ALL WooCommerce products including images, variations, and
            all related data. This cannot be undone. Please backup your database before proceeding.
        </div>

        <button id="start-delete" class="yasir-button danger">Delete All Products</button>
        <button id="refresh-count" class="yasir-button">Refresh Count</button>

        <div class="yasir-progress-container" id="progress-container">
            <div class="yasir-progress-bar">
                <div class="yasir-progress-fill" id="progress-fill"></div>
            </div>
            <div class="yasir-progress-text" id="progress-text">0% Complete</div>
        </div>
    </div>

    <div class="yasir-card">
        <h2>Process Log</h2>
        <div class="yasir-log" id="process-log">Ready to start deletion process...</div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        let totalProducts = 0;
        let totalVariations = 0;
        let processedProducts = 0;
        let isProcessing = false;

        // Get initial product count with loading state
        logMessage('Initializing WooCommerce Bulk Delete Products plugin...');
        getProductsCount();
        loadProductTypes();
        initializeAdvancedFilters();

        // Event handlers
        $('#select-all-types').on('change', function() {
            const isChecked = $(this).is(':checked');
            $('#product-types-list input[type="checkbox"]').prop('checked', isChecked);
            updateFilterSummary();
            getProductsCount();
        });

        // Advanced Filters Toggle
        $('#advanced-filters-toggle').on('click', function() {
            const container = $('#advanced-filters-container');
            const toggleIcon = $(this).find('.toggle-icon');
            
            if (container.is(':visible')) {
                container.slideUp(300);
                toggleIcon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
                $(this).removeClass('active');
            } else {
                container.slideDown(300);
                toggleIcon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
                $(this).addClass('active');
                loadCategories();
                loadTags();
            }
        });

        // Filter Section Toggle
        $('.yasir-filter-header').on('click', function() {
            const target = $(this).data('target');
            const content = $('#' + target);
            const toggle = $(this).find('.yasir-filter-toggle');
            
            if (content.hasClass('collapsed')) {
                content.removeClass('collapsed').slideDown(300);
                toggle.removeClass('collapsed');
            } else {
                content.addClass('collapsed').slideUp(300);
                toggle.addClass('collapsed');
            }
        });

        // Filter change handlers
        $(document).on('change', 'input[type="checkbox"], input[type="number"], input[type="date"], select', function() {
            if ($(this).closest('#advanced-filters-container').length > 0) {
                updateFilterSummary();
                getProductsCount();
            }
        });

        // Select All handlers for different sections
        $('#select-all-status').on('change', function() {
            const isChecked = $(this).is(':checked');
            $('input[name="product_status[]"]').prop('checked', isChecked);
            updateFilterSummary();
            getProductsCount();
        });

        // Search functionality for categories and tags
        $('#category-search').on('input', function() {
            const searchTerm = $(this).val().toLowerCase();
            $('#categories-list .yasir-checkbox-item').each(function() {
                const text = $(this).find('label').text().toLowerCase();
                $(this).toggle(text.includes(searchTerm));
            });
        });

        $('#tag-search').on('input', function() {
            const searchTerm = $(this).val().toLowerCase();
            $('#tags-list .yasir-checkbox-item').each(function() {
                const text = $(this).find('label').text().toLowerCase();
                $(this).toggle(text.includes(searchTerm));
            });
        });

        function initializeAdvancedFilters() {
            // Initialize filter summary
            updateFilterSummary();
            
            // Set default date ranges if needed
            const today = new Date().toISOString().split('T')[0];
            // You can set default dates here if needed
        }

        function loadCategories() {
            const categoriesList = $('#categories-list');
            if (categoriesList.find('.yasir-checkbox-item').length > 0) {
                return; // Already loaded
            }

            $.ajax({
                url: wcBulkDeleteProducts.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_product_categories',
                    nonce: wcBulkDeleteProducts.nonce
                },
                success: function(response) {
                    if (response.success && response.data.categories) {
                        categoriesList.empty();
                        
                        response.data.categories.forEach(function(category) {
                            const checkbox = $('<div class="yasir-checkbox-item">' +
                                '<input type="checkbox" id="cat-' + category.id + '" name="category_filter[]" value="' + category.id + '">' +
                                '<label for="cat-' + category.id + '">' + category.name + ' (' + category.count + ')</label>' +
                                '</div>');
                            categoriesList.append(checkbox);
                        });

                        logMessage('Categories loaded: ' + response.data.categories.length + ' categories found');
                    } else {
                        categoriesList.html('<div style="text-align: center; color: #dc3545; padding: 20px;">Error loading categories</div>');
                    }
                },
                error: function(xhr, status, error) {
                    logMessage('AJAX error loading categories: ' + error);
                    categoriesList.html('<div style="text-align: center; color: #dc3545; padding: 20px;">Error loading categories</div>');
                }
            });
        }

        function loadTags() {
            const tagsList = $('#tags-list');
            if (tagsList.find('.yasir-checkbox-item').length > 0) {
                return; // Already loaded
            }

            $.ajax({
                url: wcBulkDeleteProducts.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_product_tags',
                    nonce: wcBulkDeleteProducts.nonce
                },
                success: function(response) {
                    if (response.success && response.data.tags) {
                        tagsList.empty();
                        
                        response.data.tags.forEach(function(tag) {
                            const checkbox = $('<div class="yasir-checkbox-item">' +
                                '<input type="checkbox" id="tag-' + tag.id + '" name="tag_filter[]" value="' + tag.id + '">' +
                                '<label for="tag-' + tag.id + '">' + tag.name + ' (' + tag.count + ')</label>' +
                                '</div>');
                            tagsList.append(checkbox);
                        });

                        logMessage('Tags loaded: ' + response.data.tags.length + ' tags found');
                    } else {
                        tagsList.html('<div style="text-align: center; color: #dc3545; padding: 20px;">Error loading tags</div>');
                    }
                },
                error: function(xhr, status, error) {
                    logMessage('AJAX error loading tags: ' + error);
                    tagsList.html('<div style="text-align: center; color: #dc3545; padding: 20px;">Error loading tags</div>');
                }
            });
        }

        function updateFilterSummary() {
            const filterTags = $('#filter-tags');
            const filterSummary = $('#filter-summary');
            const tags = [];

            // Product Types
            const selectedTypes = getSelectedProductTypes();
            if (selectedTypes.length > 0 && selectedTypes.length < $('#product-types-list input[type="checkbox"]').length) {
                tags.push('Product Types: ' + selectedTypes.length + ' selected');
            }

            // Product Status
            const selectedStatus = $('input[name="product_status[]"]:checked').length;
            if (selectedStatus > 0) {
                tags.push('Status: ' + selectedStatus + ' selected');
            }

            // Visibility
            const selectedVisibility = $('input[name="product_visibility[]"]:checked').length;
            if (selectedVisibility > 0) {
                tags.push('Visibility: ' + selectedVisibility + ' selected');
            }

            // Stock Status
            const selectedStock = $('input[name="stock_status[]"]:checked').length;
            if (selectedStock > 0) {
                tags.push('Stock: ' + selectedStock + ' selected');
            }

            // Categories
            const selectedCategories = $('input[name="category_filter[]"]:checked').length;
            if (selectedCategories > 0) {
                tags.push('Categories: ' + selectedCategories + ' selected');
            }

            // Tags
            const selectedTags = $('input[name="tag_filter[]"]:checked').length;
            if (selectedTags > 0) {
                tags.push('Tags: ' + selectedTags + ' selected');
            }

            // Price Range
            const minPrice = $('#min-price').val();
            const maxPrice = $('#max-price').val();
            if (minPrice || maxPrice) {
                tags.push('Price Range: $' + (minPrice || '0') + ' - $' + (maxPrice || '∞'));
            }

            // Date Ranges
            const createdFrom = $('#created-from').val();
            const createdTo = $('#created-to').val();
            if (createdFrom || createdTo) {
                tags.push('Created: ' + (createdFrom || 'Any') + ' to ' + (createdTo || 'Any'));
            }

            // SKU Pattern
            const skuPattern = $('#sku-pattern').val();
            if (skuPattern) {
                tags.push('SKU Pattern: ' + skuPattern);
            }

            // Stock Quantity
            const minStock = $('#min-stock').val();
            const maxStock = $('#max-stock').val();
            if (minStock || maxStock) {
                tags.push('Stock: ' + (minStock || '0') + ' - ' + (maxStock || '∞'));
            }

            // Update filter summary
            if (tags.length > 0) {
                filterTags.empty();
                tags.forEach(function(tag) {
                    const tagElement = $('<span class="yasir-filter-tag">' + tag + ' <i class="fas fa-times"></i></span>');
                    filterTags.append(tagElement);
                });
                filterSummary.addClass('active');
            } else {
                filterSummary.removeClass('active');
            }
        }

        // Remove filter tag functionality
        $(document).on('click', '.yasir-filter-tag i', function() {
            const tag = $(this).parent();
            const tagText = tag.text().replace(' ×', '');
            
            // Clear corresponding filters based on tag text
            if (tagText.includes('Product Types')) {
                $('#product-types-list input[type="checkbox"]').prop('checked', false);
                $('#select-all-types').prop('checked', false);
            } else if (tagText.includes('Status')) {
                $('input[name="product_status[]"]').prop('checked', false);
                $('#select-all-status').prop('checked', false);
            } else if (tagText.includes('Price Range')) {
                $('#min-price, #max-price').val('');
            } else if (tagText.includes('Created')) {
                $('#created-from, #created-to').val('');
            } else if (tagText.includes('SKU Pattern')) {
                $('#sku-pattern').val('');
            }
            
            updateFilterSummary();
            getProductsCount();
        });

        function getAllFilters() {
            const filters = {};
            
            // Product Types
            filters.product_types = getSelectedProductTypes();
            
            // Product Status
            filters.product_status = [];
            $('input[name="product_status[]"]:checked').each(function() {
                filters.product_status.push($(this).val());
            });
            
            // Visibility
            filters.product_visibility = [];
            $('input[name="product_visibility[]"]:checked').each(function() {
                filters.product_visibility.push($(this).val());
            });
            
            // Stock Status
            filters.stock_status = [];
            $('input[name="stock_status[]"]:checked').each(function() {
                filters.stock_status.push($(this).val());
            });
            
            // Categories
            filters.categories = [];
            $('input[name="category_filter[]"]:checked').each(function() {
                filters.categories.push($(this).val());
            });
            
            // Tags
            filters.tags = [];
            $('input[name="tag_filter[]"]:checked').each(function() {
                filters.tags.push($(this).val());
            });
            
            // Price Range
            filters.min_price = $('#min-price').val();
            filters.max_price = $('#max-price').val();
            
            // Date Ranges
            filters.created_from = $('#created-from').val();
            filters.created_to = $('#created-to').val();
            filters.modified_from = $('#modified-from').val();
            filters.modified_to = $('#modified-to').val();
            
            // SKU
            filters.sku_pattern = $('#sku-pattern').val();
            filters.without_sku = $('#without-sku').is(':checked');
            
            // Stock Quantity
            filters.min_stock = $('#min-stock').val();
            filters.max_stock = $('#max-stock').val();
            
            // Weight
            filters.min_weight = $('#min-weight').val();
            filters.max_weight = $('#max-weight').val();
            
            // Sales
            filters.min_sales = $('#min-sales').val();
            filters.max_sales = $('#max-sales').val();
            filters.zero_sales = $('#zero-sales').is(':checked');
            
            // Reviews
            filters.min_rating = $('#min-rating').val();
            filters.no_reviews = $('#no-reviews').is(':checked');
            filters.low_reviews = $('#low-reviews').is(':checked');
            
            // Advanced
            filters.author_id = $('#author-id').val();
            filters.user_role = $('#user-role').val();
            
            // Sale filters
            filters.sale_filters = [];
            $('input[name="sale_filter[]"]:checked').each(function() {
                filters.sale_filters.push($(this).val());
            });
            
            // Duplicate filters
            filters.duplicate_filters = [];
            $('input[name="duplicate_filter[]"]:checked').each(function() {
                filters.duplicate_filters.push($(this).val());
            });
            
            // Advanced filters
            filters.advanced_filters = [];
            $('input[name="advanced_filter[]"]:checked').each(function() {
                filters.advanced_filters.push($(this).val());
            });
            
            return filters;
        }

        $('#refresh-count').on('click', function() {
            if (!$(this).hasClass('loading')) {
                getProductsCount();
            }
        });

        $('#only-without-sku').on('change', function() {
            getProductsCount();
            updateWarningMessage();
        });

        function updateWarningMessage() {
            const onlyWithoutSku = $('#only-without-sku').is(':checked');
            const warningElement = $('#warning-message');
            const buttonElement = $('#start-delete');

            if (onlyWithoutSku) {
                warningElement.html(
                    '⚠️ WARNING: This action will permanently delete all WooCommerce products WITHOUT SKU including images, variations, and all related data. This cannot be undone. Please backup your database before proceeding.'
                );
                buttonElement.text('Delete Products Without SKU');
            } else {
                warningElement.html(
                    '⚠️ WARNING: This action will permanently delete ALL WooCommerce products including images, variations, and all related data. This cannot be undone. Please backup your database before proceeding.'
                );
                buttonElement.text('Delete All Products');
            }
        }

        $('#start-delete').on('click', function() {
            const onlyWithoutSku = $('#only-without-sku').is(':checked');
            const warningMessage = onlyWithoutSku ?
                'Are you sure you want to delete all products WITHOUT SKU and their data? This cannot be undone!' :
                'Are you absolutely sure you want to delete ALL products and their data? This cannot be undone!';

            const finalWarningMessage = onlyWithoutSku ?
                'Last chance! This will permanently delete all WooCommerce products WITHOUT SKU, images, variations, and related data. Continue?' :
                'Last chance! This will permanently delete all WooCommerce products, images, variations, and related data. Continue?';

            if (!confirm(warningMessage)) {
                return;
            }

            if (!confirm(finalWarningMessage)) {
                return;
            }

            startDeletion();
        });

        function getProductsCount() {
            const refreshButton = $('#refresh-count');
            const statsContainer = $('.yasir-stats');

            // Start loading state
            refreshButton.addClass('loading');
            refreshButton.html('<span class="yasir-loading-spinner"></span>Refreshing...');
            statsContainer.addClass('loading');

            // Set loading text for stats
            $('#total-products').text('Loading...');
            $('#total-variations').text('Loading...');
            $('#remaining-products').text('Loading...');

            const filters = getAllFilters();

            $.ajax({
                url: wcBulkDeleteProducts.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_products_count',
                    nonce: wcBulkDeleteProducts.nonce,
                    ...filters
                },
                success: function(response) {
                    if (response.success) {
                        totalProducts = response.data.products;
                        totalVariations = response.data.variations;
                        $('#total-products').text(totalProducts);
                        $('#total-variations').text(totalVariations);
                        $('#remaining-products').text(totalProducts - processedProducts);

                        logMessage('Total products found: ' + totalProducts + ' (Variations: ' + totalVariations + ')');
                    } else {
                        logMessage('Error getting product count: ' + (response.data ? response.data
                            .message : 'Unknown error'));
                        // Set error state
                        $('#total-products').text('Error');
                        $('#total-variations').text('Error');
                        $('#remaining-products').text('Error');
                    }
                },
                error: function(xhr, status, error) {
                    logMessage('AJAX error getting product count: ' + error);
                    // Set error state
                    $('#total-products').text('Error');
                    $('#total-variations').text('Error');
                    $('#remaining-products').text('Error');
                },
                complete: function() {
                    // End loading state
                    refreshButton.removeClass('loading');
                    refreshButton.html('Refresh Count');
                    statsContainer.removeClass('loading');
                }
            });
        }

        function loadProductTypes() {
            const loadingSpinner = $('#product-types-loading');
            const productTypesList = $('#product-types-list');

            loadingSpinner.show();

            $.ajax({
                url: wcBulkDeleteProducts.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_product_types',
                    nonce: wcBulkDeleteProducts.nonce
                },
                success: function(response) {
                    if (response.success && response.data.product_types) {
                        productTypesList.empty();
                        
                        response.data.product_types.forEach(function(productType) {
                            const checkbox = $('<div class="product-type-option">' +
                                '<label>' +
                                '<input type="checkbox" value="' + productType.type + '" checked> ' +
                                productType.label + ' (' + productType.count + ')' +
                                '</label>' +
                                '</div>');
                            productTypesList.append(checkbox);
                        });

                        // Add event handler for individual checkboxes
                        $('#product-types-list input[type="checkbox"]').on('change', function() {
                            updateSelectAllState();
                            getProductsCount();
                        });

                        logMessage('Product types loaded: ' + response.data.product_types.length + ' types found');
                    } else {
                        logMessage('Error loading product types: ' + (response.data ? response.data.message : 'Unknown error'));
                        productTypesList.html('<p style="color: #dc3545;">Error loading product types</p>');
                    }
                },
                error: function(xhr, status, error) {
                    logMessage('AJAX error loading product types: ' + error);
                    productTypesList.html('<p style="color: #dc3545;">Error loading product types</p>');
                },
                complete: function() {
                    loadingSpinner.hide();
                }
            });
        }

        function getSelectedProductTypes() {
            const selectedTypes = [];
            $('#product-types-list input[type="checkbox"]:checked').each(function() {
                selectedTypes.push($(this).val());
            });
            return selectedTypes;
        }

        function updateSelectAllState() {
            const totalCheckboxes = $('#product-types-list input[type="checkbox"]').length;
            const checkedCheckboxes = $('#product-types-list input[type="checkbox"]:checked').length;
            
            if (checkedCheckboxes === 0) {
                $('#select-all-types').prop('indeterminate', false).prop('checked', false);
            } else if (checkedCheckboxes === totalCheckboxes) {
                $('#select-all-types').prop('indeterminate', false).prop('checked', true);
            } else {
                $('#select-all-types').prop('indeterminate', true);
            }
        }

        function startDeletion() {
            if (isProcessing) return;

            isProcessing = true;
            processedProducts = 0;

            $('#start-delete').prop('disabled', true);
            $('#progress-container').show();

            // Get deletion options
            const options = {
                delete_images: $('#delete-images').is(':checked'),
                delete_variations: $('#delete-variations').is(':checked'),
                delete_meta: $('#delete-meta').is(':checked'),
                delete_terms: $('#delete-terms').is(':checked'),
                ...getAllFilters()
            };

            logMessage('Starting bulk deletion process with options: ' + JSON.stringify(options));
            processBatch(options);
        }

        function processBatch(options) {
            $.ajax({
                url: wcBulkDeleteProducts.ajax_url,
                type: 'POST',
                data: {
                    action: 'delete_products_batch',
                    nonce: wcBulkDeleteProducts.nonce,
                    options: options
                },
                success: function(response) {
                    if (response.success) {
                        processedProducts += response.data.deleted;
                        updateProgress();
                        logMessage('Batch processed: ' + response.data.deleted +
                            ' products deleted (' + response.data.images_deleted + ' images, ' +
                            response.data.variations_deleted + ' variations)');

                        if (response.data.remaining > 0) {
                            // Continue processing
                            setTimeout(function() {
                                processBatch(options);
                            }, 1500); // 1.5 second delay between batches (longer due to complexity)
                        } else {
                            // All done
                            completeDeletion();
                        }
                    } else {
                        logMessage('Error: ' + (response.data ? response.data.message :
                            'Unknown error'));
                        completeDeletion();
                    }
                },
                error: function(xhr, status, error) {
                    logMessage('AJAX error occurred: ' + error);
                    completeDeletion();
                }
            });
        }

        function updateProgress() {
            const percentage = totalProducts > 0 ? Math.round((processedProducts / totalProducts) * 100) : 0;
            $('#progress-fill').css('width', percentage + '%');
            $('#progress-text').text(percentage + '% Complete (' + processedProducts + '/' + totalProducts +
                ')');
            $('#processed-products').text(processedProducts);
            $('#remaining-products').text(totalProducts - processedProducts);
        }

        function completeDeletion() {
            isProcessing = false;
            $('#start-delete').prop('disabled', false);

            if (processedProducts === totalProducts) {
                logMessage(
                    '✅ Deletion completed successfully! All products and related data have been removed.');
                showMessage('All products have been successfully deleted!', 'success');
            } else {
                logMessage('⚠️ Deletion completed with some remaining products.');
                showMessage('Deletion process completed. Check logs for details.', 'warning');
            }

            getProductsCount();
        }

        function logMessage(message) {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = '[' + timestamp + '] ' + message + '\n';
            $('#process-log').append(logEntry);
            $('#process-log').scrollTop($('#process-log')[0].scrollHeight);
        }

        function showMessage(message, type) {
            const messageDiv = $('<div class="yasir-' + type + '">' + message + '</div>');
            $('.yasir-card').first().after(messageDiv);
            setTimeout(function() {
                messageDiv.fadeOut(500, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    });
    </script>
</div>
<?php
    }

    public function get_products_count()
    {
        check_ajax_referer('wc_bulk_delete_products_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        global $wpdb;

        $only_without_sku = isset($_POST['only_without_sku']) && ($_POST['only_without_sku'] === 'true' || $_POST['only_without_sku'] === true);
        $product_types = isset($_POST['product_types']) ? $_POST['product_types'] : array();

        // Build the base query
        $where_conditions = array("p.post_type = 'product'");
        $joins = array();

        // Add SKU filter if needed
        if ($only_without_sku) {
            $joins[] = "LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'";
            $where_conditions[] = "(pm_sku.meta_value IS NULL OR pm_sku.meta_value = '')";
        }

        // Add product type filter if needed
        if (!empty($product_types)) {
            $joins[] = "LEFT JOIN {$wpdb->postmeta} pm_type ON p.ID = pm_type.post_id AND pm_type.meta_key = '_product_type'";
            
            $type_conditions = array();
            foreach ($product_types as $type) {
                if ($type === 'simple') {
                    // Simple products can have NULL, empty, or 'simple' as product type
                    $type_conditions[] = "(pm_type.meta_value IS NULL OR pm_type.meta_value = '' OR pm_type.meta_value = 'simple')";
                } else {
                    $type_conditions[] = $wpdb->prepare("pm_type.meta_value = %s", $type);
                }
            }
            $where_conditions[] = "(" . implode(" OR ", $type_conditions) . ")";
        }

        // Build and execute the query
        $joins_sql = !empty($joins) ? implode(" ", $joins) : "";
        $where_sql = implode(" AND ", $where_conditions);
        
        $products_count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$joins_sql} WHERE {$where_sql}"
        );

        // Count variations (always all variations for now)
        $variations_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type = 'product_variation'"
        );

        $filter_text = $only_without_sku ? " (without SKU filter applied)" : " (all products)";
        if (!empty($product_types)) {
            $filter_text .= " (product types: " . implode(", ", $product_types) . ")";
        }
        $this->log_message("Product count requested: Products: {$products_count}{$filter_text}, Variations: {$variations_count}");

        wp_send_json_success(array(
            'products' => intval($products_count),
            'variations' => intval($variations_count)
        ));
    }

    public function delete_products_batch()
    {
        check_ajax_referer('wc_bulk_delete_products_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        global $wpdb;

        // Get deletion options
        $options = isset($_POST['options']) ? $_POST['options'] : array();
        $delete_images = isset($options['delete_images']) ? $options['delete_images'] : true;
        $delete_variations = isset($options['delete_variations']) ? $options['delete_variations'] : true;
        $delete_meta = isset($options['delete_meta']) ? $options['delete_meta'] : true;
        $delete_terms = isset($options['delete_terms']) ? $options['delete_terms'] : true;
        $only_without_sku = isset($options['only_without_sku']) && ($options['only_without_sku'] === 'true' || $options['only_without_sku'] === true);
        $product_types = isset($options['product_types']) ? $options['product_types'] : array();

        $this->log_message("Debug: Received options: " . json_encode($options));
        $this->log_message("Debug: Processed only_without_sku: " . ($only_without_sku ? 'true' : 'false'));
        $this->log_message("Debug: Product types filter: " . (!empty($product_types) ? implode(", ", $product_types) : 'none'));

        // Build the query for getting product IDs
        $where_conditions = array("p.post_type = 'product'");
        $joins = array();

        // Add SKU filter if needed
        if ($only_without_sku) {
            $joins[] = "LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'";
            $where_conditions[] = "(pm_sku.meta_value IS NULL OR pm_sku.meta_value = '')";
        }

        // Add product type filter if needed
        if (!empty($product_types)) {
            $joins[] = "LEFT JOIN {$wpdb->postmeta} pm_type ON p.ID = pm_type.post_id AND pm_type.meta_key = '_product_type'";
            
            $type_conditions = array();
            foreach ($product_types as $type) {
                if ($type === 'simple') {
                    // Simple products can have NULL, empty, or 'simple' as product type
                    $type_conditions[] = "(pm_type.meta_value IS NULL OR pm_type.meta_value = '' OR pm_type.meta_value = 'simple')";
                } else {
                    $type_conditions[] = $wpdb->prepare("pm_type.meta_value = %s", $type);
                }
            }
            $where_conditions[] = "(" . implode(" OR ", $type_conditions) . ")";
        }

        // Build and execute the query
        $joins_sql = !empty($joins) ? implode(" ", $joins) : "";
        $where_sql = implode(" AND ", $where_conditions);
        
        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$joins_sql} WHERE {$where_sql} LIMIT %d",
                $this->batch_size
            )
        );

        $deleted_count = 0;
        $images_deleted = 0;
        $variations_deleted = 0;

        $this->log_message("Debug: Found " . count($product_ids) . " products to process. SKU filter: " . ($only_without_sku ? 'enabled' : 'disabled'));

        if (!empty($product_ids)) {
            foreach ($product_ids as $product_id) {
                try {
                    $this->log_message("Debug: Processing product ID: {$product_id}");
                    
                    // Get product images before deletion
                    $image_ids = array();
                    if ($delete_images) {
                        $image_ids = $this->get_product_image_ids($product_id);
                    }

                    // Get product variations before deletion
                    $variation_ids = array();
                    if ($delete_variations) {
                        $variation_ids = $wpdb->get_col($wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
                            $product_id
                        ));
                    }

                    // Delete using WooCommerce function if available
                    $result = false;
                    if (function_exists('wc_delete_product')) {
                        $result = wc_delete_product($product_id, true);
                        $this->log_message("Debug: wc_delete_product result for {$product_id}: " . ($result ? 'success' : 'failed'));
                    }
                    
                    // If WooCommerce deletion failed or function doesn't exist, use WordPress function
                    if (!$result) {
                        $result = wp_delete_post($product_id, true);
                        $this->log_message("Debug: wp_delete_post result for {$product_id}: " . ($result ? 'success' : 'failed'));
                    }
                    
                    if ($result) {
                        $deleted_count++;
                    } else {
                        $this->log_message("Warning: Failed to delete product {$product_id}");
                    }

                    // Delete variations if requested
                    if ($delete_variations && !empty($variation_ids)) {
                        foreach ($variation_ids as $variation_id) {
                            wp_delete_post($variation_id, true);
                            $variations_deleted++;
                        }
                    }

                    // Delete product images if requested
                    if ($delete_images && !empty($image_ids)) {
                        foreach ($image_ids as $image_id) {
                            wp_delete_attachment($image_id, true);
                            $images_deleted++;
                        }
                    }

                    // Delete product meta if requested
                    if ($delete_meta) {
                        $wpdb->delete($wpdb->postmeta, array('post_id' => $product_id));
                    }

                    // Delete product terms if requested
                    if ($delete_terms) {
                        $wpdb->delete($wpdb->term_relationships, array('object_id' => $product_id));
                    }
                } catch (Exception $e) {
                    $this->log_message("Error deleting product {$product_id}: " . $e->getMessage());
                    continue;
                }
            }
        }

        // Get remaining count based on filters
        $remaining = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$joins_sql} WHERE {$where_sql}"
        );

        $filter_text = "";
        if ($only_without_sku) {
            $filter_text .= " (SKU filter: without SKU)";
        }
        if (!empty($product_types)) {
            $filter_text .= " (product types: " . implode(", ", $product_types) . ")";
        }
        $this->log_message("Batch processed: {$deleted_count} products deleted{$filter_text}, {$images_deleted} images deleted, {$variations_deleted} variations deleted, {$remaining} remaining");

        wp_send_json_success(array(
            'deleted' => $deleted_count,
            'images_deleted' => $images_deleted,
            'variations_deleted' => $variations_deleted,
            'remaining' => intval($remaining)
        ));
    }

    private function get_product_image_ids($product_id)
    {
        global $wpdb;

        $image_ids = array();

        // Get featured image
        $featured_image = get_post_thumbnail_id($product_id);
        if ($featured_image) {
            $image_ids[] = $featured_image;
        }

        // Get gallery images
        $gallery_images = get_post_meta($product_id, '_product_image_gallery', true);
        if ($gallery_images) {
            $gallery_array = explode(',', $gallery_images);
            $image_ids = array_merge($image_ids, $gallery_array);
        }

        // Get variation images
        $variation_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
            $product_id
        ));

        foreach ($variation_ids as $variation_id) {
            $variation_image = get_post_thumbnail_id($variation_id);
            if ($variation_image) {
                $image_ids[] = $variation_image;
            }
        }

        return array_unique(array_filter($image_ids));
    }

    public function get_product_types()
    {
        check_ajax_referer('wc_bulk_delete_products_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        global $wpdb;

        // Get all available product types from WooCommerce
        $product_types = array();
        
        if (function_exists('wc_get_product_types')) {
            // Get WooCommerce product types
            $wc_product_types = wc_get_product_types();
            
            // Get actual product types used in the database
            $used_types = $wpdb->get_col(
                "SELECT DISTINCT pm.meta_value 
                 FROM {$wpdb->postmeta} pm 
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                 WHERE pm.meta_key = '_product_type' 
                 AND p.post_type = 'product' 
                 AND pm.meta_value != ''"
            );

            // Also check for products without _product_type meta (they default to 'simple')
            $products_without_type = $wpdb->get_var(
                "SELECT COUNT(p.ID) 
                 FROM {$wpdb->posts} p 
                 LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_product_type'
                 WHERE p.post_type = 'product' 
                 AND (pm.meta_value IS NULL OR pm.meta_value = '')"
            );

            if ($products_without_type > 0) {
                $used_types[] = 'simple';
            }

            // Get counts for each product type
            foreach ($used_types as $type) {
                if (isset($wc_product_types[$type])) {
                    $count = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(p.ID) 
                         FROM {$wpdb->posts} p 
                         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_product_type'
                         WHERE p.post_type = 'product' 
                         AND (
                             (pm.meta_value = %s) OR 
                             (%s = 'simple' AND (pm.meta_value IS NULL OR pm.meta_value = ''))
                         )",
                        $type,
                        $type
                    ));

                    if ($count > 0) {
                        $product_types[] = array(
                            'type' => $type,
                            'label' => $wc_product_types[$type],
                            'count' => intval($count)
                        );
                    }
                }
            }
        }

        $this->log_message("Product types requested: " . count($product_types) . " types found");

        wp_send_json_success(array(
            'product_types' => $product_types
        ));
    }

    public function get_product_categories()
    {
        check_ajax_referer('wc_bulk_delete_products_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        global $wpdb;

        $categories = array();

        // Get product categories with product counts
        $terms = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                // Get actual product count for this category
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.ID) 
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                     WHERE p.post_type = 'product'
                     AND p.post_status = 'publish'
                     AND tt.term_id = %d
                     AND tt.taxonomy = 'product_cat'",
                    $term->term_id
                ));

                if ($count > 0) {
                    $categories[] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'count' => intval($count)
                    );
                }
            }
        }

        $this->log_message("Product categories requested: " . count($categories) . " categories found");

        wp_send_json_success(array(
            'categories' => $categories
        ));
    }

    public function get_product_tags()
    {
        check_ajax_referer('wc_bulk_delete_products_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        global $wpdb;

        $tags = array();

        // Get product tags with product counts
        $terms = get_terms(array(
            'taxonomy' => 'product_tag',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));

        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                // Get actual product count for this tag
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.ID) 
                     FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                     INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                     WHERE p.post_type = 'product'
                     AND p.post_status = 'publish'
                     AND tt.term_id = %d
                     AND tt.taxonomy = 'product_tag'",
                    $term->term_id
                ));

                if ($count > 0) {
                    $tags[] = array(
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'count' => intval($count)
                    );
                }
            }
        }

        $this->log_message("Product tags requested: " . count($tags) . " tags found");

        wp_send_json_success(array(
            'tags' => $tags
        ));
    }

    private function log_message($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
}

// Initialize the plugin
function init_woocommerce_bulk_delete_products()
{
    if (class_exists('WooCommerce') || in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        new WooCommerce_Bulk_Delete_Products();
    } else {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>WooCommerce Bulk Delete Products requires WooCommerce to be installed and active.</p></div>';
        });
    }
}

// Hook into plugins_loaded to ensure WooCommerce is loaded first
add_action('plugins_loaded', 'init_woocommerce_bulk_delete_products');
?>