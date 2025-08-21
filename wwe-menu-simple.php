<?php
/**
 * UPS WWE Simple Menu - Globe icon direct to Customs
 */

// Security check
if (!defined("ABSPATH")) exit;

// Remove existing UPS WWE submenus from WooCommerce
add_action("admin_menu", function() {
    // Remove sous-menu WooCommerce si il existe
    remove_submenu_page("woocommerce", "wwe-ups-customs");
    
    // Add simple globe menu - direct to customs page
    add_menu_page(
        "UPS WWE Customs",
        "UPS WWE",
        "manage_woocommerce", 
        "wwe-ups-customs",
        "wwe_ups_customs_page",
        "dashicons-admin-site-alt3",
        56.5
    );
}, 999);

// Direct to customs page - same as WooCommerce submenu
function wwe_ups_customs_page() {
    // Check if class exists and file is loaded
    if (!class_exists("WWE_UPS_Customs_Dashboard")) {
        // Try to include the file
        $customs_file = plugin_dir_path(__FILE__) . "includes/class-wwe-ups-customs-dashboard.php";
        if (file_exists($customs_file)) {
            require_once $customs_file;
        }
    }
    
    if (class_exists("WWE_UPS_Customs_Dashboard")) {
        $customs = new WWE_UPS_Customs_Dashboard();
        $customs->render_dashboard(); // Méthode correcte
    } else {
        echo "<div class=\"wrap\">
            <h1>🌐 UPS WorldWide Economy Customs</h1>
            <p><strong>Erreur:</strong> Classe WWE_UPS_Customs_Dashboard non trouvée.</p>
            <p><a href=\"" . admin_url("admin.php?page=wc-settings&tab=shipping&section=ups_shipping") . "\">← Retour aux paramètres UPS</a></p>
        </div>";
    }
}
