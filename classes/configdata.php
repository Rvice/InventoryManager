<?php
/**
 ***********************************************************************************************
 * Config data class for the Admidio plugin InventoryManager
 *
 * @see         https://github.com/MightyMCoder/InventoryManager/ The InventoryManager GitHub project
 * @author      MightyMCoder
 * @copyright   2024 - today MightyMCoder
 * @license     https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0 only
 * 
 * Note:
 * 
 * Using this character combination DB_TOKEN, configuration data managed as an array at runtime
 * is concatenated into a string and stored in the Admidio database. 
 * However, if the predefined character combination (#_#) is also used, for example, in the description 
 * of a configuration, the plugin will no longer be able to read the stored configuration data correctly. 
 * In this case, the predefined character combination must be changed (e.g., to !-!).
 * 
 * Warning: An uninstallation must be performed before making any changes!
 * Already stored values in the database cannot be read after a change!
 ***********************************************************************************************
 */

class CConfigDataPIM
{
    /**
     * Default configuration data for plugin InventoryManager
     */
    const CONFIG_DEFAULT = array(
        'Optionen' => array(
            'profile_addin' => array(
                'ITEMNAME',
                'LAST_RECEIVER'
            ),
            'file_name' => 'InventoryManager',
            'add_date' => 0,
            'allow_keeper_edit' => 0,
            'allowed_keeper_edit_fields' => array(
                'IN_INVENTORY',
                'LAST_RECEIVER',
                'RECEIVED_ON',
                'RECEIVED_BACK_ON'
            ),
            'current_user_default_keeper' => 0,
            'allow_negative_numbers' => 1,
            'decimal_step' => 0.1,
            'field_date_time_format' => 'date',
            'disable_borrowing' => 0,
        ),
        'Plugininformationen' => array(
            'version' => '',
            'beta-version' => '',
            'stand' => '',
        ),
        'access' => array(
            'preferences' => array(0)
        )
    );

    /**
     * Database token for plugin InventoryManager
     */
    const DB_TOKEN = '#_#';
}
