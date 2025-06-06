<?php
/**
 ***********************************************************************************************
 * InventoryManager
 *
 * Version 1.1.7
 *
 * InventoryManager is an Admidio plugin for managing the inventory of an organisation.
 * 
 * Compatible with Admidio version 4.3
 * 
 * @see         https://github.com/MightyMCoder/InventoryManager/ The InventoryManager GitHub project
 * @author      MightyMCoder
 * @copyright   2024 - today MightyMCoder
 * @license     https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0 only
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License (https://github.com/MightyMCoder/InventoryManager/blob/master/LICENSE)
 * 
 * 
 * @note:
 *  - InventoryManager is based on KeyManager by rmbinder (https://github.com/rmbinder/KeyManager)
 * 
 * 
 * Parameters:
 * mode             : Output(html, print, csv-ms, csv-oo, pdf, pdfl, xlsx, ods)
 * filter_string    : general filter string
 * filter_category  : filter for category
 * filter_keeper    : filter for keeper
 * show_all         : 0 - (Default) show active items only
 *                    1 - show all items (also made to former)
 * same_side        : 0 - (Default) side was called by another side
 *                    1 - internal call of the side
 * 
 * 
 * Methods:
 * formatSpreadsheet($spreadsheet, $data, $containsHeadline)    : Format the spreadsheet
 ***********************************************************************************************
 */

// PhpSpreadsheet namespaces
 use PhpOffice\PhpSpreadsheet\Spreadsheet;
 use PhpOffice\PhpSpreadsheet\Writer\Csv;
 use PhpOffice\PhpSpreadsheet\Writer\Ods;
 use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once(__DIR__ . '/../../adm_program/system/common.php');
require_once(__DIR__ . '/common_function.php');
require_once(__DIR__ . '/classes/items.php');
require_once(__DIR__ . '/classes/configtable.php');

// Access only with valid login
require_once(__DIR__ . '/../../adm_program/system/login_valid.php');

//$scriptName is the name as it must be entered in the menu, without any preceding folders such as /playground/adm_plugins/InventoryManager...
$scriptName = substr($_SERVER['SCRIPT_NAME'], strpos($_SERVER['SCRIPT_NAME'], FOLDER_PLUGINS));

// only authorized user are allowed to start this module
if (!isUserAuthorizedForPIM($scriptName)) {
    $gMessage->show($gL10n->get('SYS_NO_RIGHTS'));
}

$sessionDefaults = array(
    'filter_string' => '',
    'filter_category' => '',
    'filter_keeper' => 0,
    'show_all' => false
);

// check if plugin need to be updated
$pPreferences = new CConfigTablePIM();
$pPreferences->checkForUpdate() ? $pPreferences->init() : $pPreferences->read();
$disableBorrowing = $pPreferences->config['Optionen']['disable_borrowing'] ;

// check if user is authorized for preferences panel
if (isUserAuthorizedForPreferencesPIM()) {
    $authorizedForPreferences = true;
}
else {
    $authorizedForPreferences = false;
}

foreach ($sessionDefaults as $key => $default) {
    if (!isset($_SESSION['pInventoryManager'][$key])) {
        $_SESSION['pInventoryManager'][$key] = $default;
    }
}

$getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'html',
        'validValues' => ['csv-ms', 'csv-oo', 'html', 'print', 'pdf', 'pdfl', 'xlsx', 'ods']
    )
);
$getFilterString = admFuncVariableIsValid($_GET, 'filter_string', 'string');
$getFilterCategory = admFuncVariableIsValid($_GET, 'filter_category', 'string');
$getFilterKeeper = admFuncVariableIsValid($_GET, 'filter_keeper', 'int');
$getShowAll = admFuncVariableIsValid($_GET, 'show_all', 'bool', array('defaultValue' => false));
$getSameSide = admFuncVariableIsValid($_GET, 'same_side', 'bool', array('defaultValue' => false));

if ($getSameSide) {
    $_SESSION['pInventoryManager'] = array(
        'filter_string' => $getFilterString,
        'filter_category' => $getFilterCategory,
        'filter_keeper' => $getFilterKeeper,
        'show_all' => $getShowAll
    );
}
else {
    $getFilterString = $_SESSION['pInventoryManager']['filter_string'];
    $getFilterCategory = $_SESSION['pInventoryManager']['filter_category'];
    $getFilterKeeper = $_SESSION['pInventoryManager']['filter_keeper'];
    $getShowAll = $_SESSION['pInventoryManager']['show_all'];
}

// initialize some special mode parameters
$separator = '';
$valueQuotes = '';
$charset = '';
$classTable = '';
$orientation = '';
$filename = umlautePIM($pPreferences->config['Optionen']['file_name']);
if ($pPreferences->config['Optionen']['add_date']) {
    $filename .= '_' . date('Y-m-d');
}

$modeSettings = array(
    'csv-ms' => array('csv', ';', '"', 'iso-8859-1', null, null),   //mode, seperator, valueQuotes, charset, classTable, orientation
    'csv-oo' => array('csv', ',', '"', 'utf-8', null , null),
    'pdf' => array('pdf', null, null, null, 'table', 'P'),
    'pdfl' => array('pdf', null, null, null, 'table', 'L'),
    'html' => array('html', null, null, null, 'table table-condensed', null),
    'print' => array('print', null, null, null, 'table table-condensed table-striped', null),
    'xlsx' => array('xlsx', null, null, null, null, null),
    'ods' => array('ods', null, null, null, null, null)
);

if (isset($modeSettings[$getMode])) {
    [$getMode, $separator, $valueQuotes, $charset, $classTable, $orientation] = $modeSettings[$getMode];
}
// Array for valid columns visible for current user.
// Needed for PDF export to set the correct colspan for the layout
// Maybe there are hidden fields.
$arrValidColumns = array();

$csvStr         = '';                   // CSV file as string
$header         = array();              //'xlsx'
$rows           = array();              //'xlsx'
$strikethroughs = array();              //'xlsx'

// we are in keeper edit mode
if (!$authorizedForPreferences) {
    $keeperItems = new CItems($gDb, $gCurrentOrgId);
    $keeperItems->showFormerItems(true);
    $keeperItems->readItemsByUser($gCurrentOrgId, $gCurrentUser->getValue('usr_id'));
    
    if (count($keeperItems->items) > 0 && !$getSameSide) {
        $getShowAll = true;
        $getFilterKeeper = (int)$gCurrentUser->getValue('usr_id');
    }
}

// define title (html) and headline
$title = $gL10n->get('PLG_INVENTORY_MANAGER_INVENTORY_MANAGER');
$headline = $gL10n->get('PLG_INVENTORY_MANAGER_INVENTORY_MANAGER');

// if html mode and last url was not a list view then save this url to navigation stack
if ($gNavigation->count() === 0 || ($getMode == 'html' && strpos($gNavigation->getUrl(), 'inventory_manager.php') === false)) {
    $gNavigation->addStartUrl(CURRENT_URL, $headline, 'fa-warehouse');
}

$datatable = false;
$hoverRows = false;

switch ($getMode) {
    case 'csv':
    case 'ods':
    case 'xlsx':
        break;  // don't show HtmlTable

    case 'print':
        // create html page object without the custom theme files
        $page = new HtmlPage('plg-inventory-manager-main-print');
        $page->setPrintMode();
        $page->setTitle($title);
        $page->setHeadline($headline);
        $table = new HtmlTable('adm_inventory_table', $page, $hoverRows, $datatable, $classTable);
        break;

    case 'pdf':
        $pdf = new TCPDF($orientation, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // set document information
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('Admidio');
        $pdf->SetTitle($headline);

        // remove default header/footer
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(false);

        // set header and footer fonts
        $pdf->setHeaderFont(array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        $pdf->setFooterFont(array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

        // set auto page breaks
        $pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);
        $pdf->SetMargins(10, 20, 10);
        $pdf->setHeaderMargin(10);
        $pdf->setFooterMargin(0);

        // headline for PDF
        $pdf->setHeaderData('', 0, $headline, '');

        // set font
        $pdf->SetFont('times', '', 10);

        // add a page
        $pdf->AddPage();

        // Create table object for display
        $table = new HtmlTable('adm_inventory_table', null, $hoverRows, $datatable, $classTable);

        $table->addAttribute('border', '1');
        $table->addAttribute('cellpadding', '1');
        break;

    case 'html':
        $datatable = true;
        $hoverRows = true;

        $inputFilterStringLabel = '<i class="fas fa-search" alt="'.$gL10n->get('PLG_INVENTORY_MANAGER_GENERAL').'" title="'.$gL10n->get('PLG_INVENTORY_MANAGER_GENERAL').'"></i>';
        $selectBoxCategoryLabel ='<i class="fas fa-list" alt="'.$gL10n->get('PLG_INVENTORY_MANAGER_CATEGORY').'" title="'.$gL10n->get('PLG_INVENTORY_MANAGER_CATEGORY').'"></i>';
        $selectBoxKeeperLabel = '<i class="fas fa-user" alt="'.$gL10n->get('PLG_INVENTORY_MANAGER_KEEPER').'" title="'.$gL10n->get('PLG_INVENTORY_MANAGER_KEEPER').'"></i>';
        
        // create html page object
        $page = new HtmlPage('plg-inventory-manager-main-html');
        $page->setTitle($title);
        $page->setHeadline($headline);

        $page->addJavascript('
            $("#filter_category").change(function () {
                self.location.href = "'.SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/inventory_manager.php', array(
                            'mode'              => 'html',
                            'filter_string'     => $getFilterString,
                            'filter_keeper'   => $getFilterKeeper,
                            'same_side'         => true,
                            'show_all'          => $getShowAll
                        )
                    ) . '&filter_category=" + $(this).val();
            });
            $("#filter_keeper").change(function () {
                self.location.href = "'.SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/inventory_manager.php', array(
                            'mode'              => 'html',
                            'filter_string'     => $getFilterString,
                            'filter_category'   => $getFilterCategory,
                            'same_side'         => true,
                            'show_all'          => $getShowAll
                        )
                    ) . '&filter_keeper=" + $(this).val();
            });
            $("#menu_item_lists_print_view").click(function() {
                window.open("'.SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/inventory_manager.php', array(
                            'filter_string'     => $getFilterString,
                            'filter_category'   => $getFilterCategory, 
                            'filter_keeper'   => $getFilterKeeper,
                            'show_all'          => $getShowAll,  
                            'mode'              => 'print'
                        )
                    ) . '", "_blank"
                );
            });
            $("#show_all").change(function() {
                $("#navbar_inventorylist_form").submit();
            });
            $("#filter_string").change(function() {
                $("#navbar_inventorylist_form").submit();
            });',
            true
        );

        // links to print and exports
        $page->addPageFunctionsMenuItem('menu_item_lists_print_view', $gL10n->get('SYS_PRINT_PREVIEW'), 'javascript:void(0);', 'fa-print');
        $page->addPageFunctionsMenuItem('menu_item_lists_export', $gL10n->get('SYS_EXPORT_TO'), '#', 'fa-file-download');
        $page->addPageFunctionsMenuItem('menu_item_lists_xlsx', $gL10n->get('SYS_MICROSOFT_EXCEL').' (*.xlsx)',
            SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/inventory_manager.php', array(
                    'filter_string'     => $getFilterString,
                    'filter_category'   => $getFilterCategory,
                    'filter_keeper'   => $getFilterKeeper,
                    'show_all'          => $getShowAll,
                    'mode'              => 'xlsx'
                )
            ),
            'fa-file-excel', 'menu_item_lists_export'
        );
        $page->addPageFunctionsMenuItem('menu_item_lists_ods', $gL10n->get('SYS_ODF_SPREADSHEET'),
            SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/inventory_manager.php', array(
                    'filter_string'     => $getFilterString,
                    'filter_category'   => $getFilterCategory,
                    'filter_keeper'   => $getFilterKeeper,
                    'show_all'          => $getShowAll,
                    'mode'              => 'ods'
                )
            ),
            'fa-file-alt', 'menu_item_lists_export'
        );    
        $page->addPageFunctionsMenuItem('menu_item_lists_csv_ms', $gL10n->get('SYS_COMMA_SEPARATED_FILE'),
            SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/inventory_manager.php', array(
                    'filter_string'     => $getFilterString,
                    'filter_category'   => $getFilterCategory,
                    'filter_keeper'   => $getFilterKeeper,
                    'show_all'          => $getShowAll,
                    'mode'              => 'csv-ms'
                )
            ),
            'fa-file-excel', 'menu_item_lists_export'
        );
        $page->addPageFunctionsMenuItem('menu_item_lists_csv', $gL10n->get('SYS_COMMA_SEPARATED_FILE').' ('.$gL10n->get('SYS_UTF8').')',
            SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/inventory_manager.php', array(
                    'filter_string'     => $getFilterString,
                    'filter_category'   => $getFilterCategory,
                    'filter_keeper'   => $getFilterKeeper,
                    'show_all'          => $getShowAll,
                    'mode'              => 'csv-oo'
                )
            ),
            'fa-file-csv', 'menu_item_lists_export'
        );
        $page->addPageFunctionsMenuItem('menu_item_lists_pdf', $gL10n->get('SYS_PDF').' ('.$gL10n->get('SYS_PORTRAIT').')',
            SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/inventory_manager.php', array(
                    'filter_string'     => $getFilterString,
                    'filter_category'   => $getFilterCategory,
                    'filter_keeper'   => $getFilterKeeper,
                    'show_all'          => $getShowAll,
                    'mode'              => 'pdf'
                )
            ),
            'fa-file-pdf', 'menu_item_lists_export'
        );
        $page->addPageFunctionsMenuItem('menu_item_lists_pdfl', $gL10n->get('SYS_PDF').' ('.$gL10n->get('SYS_LANDSCAPE').')',
            SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/inventory_manager.php', array(
                    'filter_string'     => $getFilterString,
                    'filter_category'   => $getFilterCategory,
                    'filter_keeper'   => $getFilterKeeper,
                    'show_all'          => $getShowAll,
                    'mode'              => 'pdfl'
                )
            ),
            'fa-file-pdf', 'menu_item_lists_export'
        );
        
        if ($authorizedForPreferences) {
            $page->addPageFunctionsMenuItem('menu_preferences', $gL10n->get('SYS_SETTINGS'), SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/preferences/preferences.php'),  'fa-cog');
            $page->addPageFunctionsMenuItem('itemcreate_form_btn', $gL10n->get('PLG_INVENTORY_MANAGER_ITEM_CREATE'), SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/items/items_edit_new.php', array('item_id' => 0)), 'fas fa-plus-circle');
        } 
        
        // create filter menu with elements for role
        $filterNavbar = new HtmlNavbar('navbar_filter', '', null, 'filter');
        $form = new HtmlForm('navbar_inventorylist_form', SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_PLUGINS . PLUGIN_FOLDER_IM .'/inventory_manager.php', array('headline' => $headline)), $page, array('type' => 'navbar', 'setFocus' => false));

        $form->addInput('filter_string', $inputFilterStringLabel, $getFilterString);
          
        $getItemId = admFuncVariableIsValid($_GET, 'item_id', 'int');
        $items2 = new CItems($gDb, $gCurrentOrgId);
        $items2->showFormerItems($getShowAll);
        $items2->readItemData($getItemId, $gCurrentOrgId);
        foreach ($items2->mItemFields as $itemField) {  
            $imfNameIntern = $itemField->getValue('imf_name_intern');
        
            if ($items2->getProperty($imfNameIntern, 'imf_type') === 'DROPDOWN') {
                $arrListValues = $items2->getProperty($imfNameIntern, 'imf_value_list');
                $defaultValue  = $items2->getValue($imfNameIntern, 'database');
        
                $form->addSelectBox(
                    'filter_category',
                    $selectBoxCategoryLabel,
                    $arrListValues,
                    array(
                        'defaultValue'    => $getFilterCategory,
                        'showContextDependentFirstEntry' => true
                    )
                );
            }
        }
    
        // read all keeper
        switch ($gDbType) {
            case 'pgsql':
                $sql = 'SELECT DISTINCT imd_value, 
            CASE 
                WHEN imd_value = \'-1\' THEN \'n/a\'
                ELSE CONCAT_WS(\', \', last_name.usd_value, first_name.usd_value)
            END as keeper_name
            FROM '.TBL_INVENTORY_MANAGER_DATA.'
            INNER JOIN '.TBL_INVENTORY_MANAGER_FIELDS.'
                ON imf_id = imd_imf_id
            LEFT JOIN '. TBL_USER_DATA. ' as last_name
                ON CAST(last_name.usd_usr_id AS VARCHAR)= imd_value
                AND last_name.usd_usf_id = '. $gProfileFields->getProperty('LAST_NAME', 'usf_id'). '
            LEFT JOIN '. TBL_USER_DATA. ' as first_name
                ON CAST(first_name.usd_usr_id AS VARCHAR)= imd_value
                AND first_name.usd_usf_id = '. $gProfileFields->getProperty('FIRST_NAME', 'usf_id'). '
            WHERE (imf_org_id  = '. $gCurrentOrgId .'
                OR imf_org_id IS NULL)
            AND imf_name_intern = \'KEEPER\'
            ORDER BY keeper_name ASC;';
                break;
            case 'mysql':
            default:
                $sql = 'SELECT DISTINCT imd_value, 
            CASE 
                WHEN imd_value = -1 THEN \'n/a\'
                ELSE CONCAT_WS(\', \', last_name.usd_value, first_name.usd_value)
            END as keeper_name
            FROM '.TBL_INVENTORY_MANAGER_DATA.'
            INNER JOIN '.TBL_INVENTORY_MANAGER_FIELDS.'
                ON imf_id = imd_imf_id
            LEFT JOIN '. TBL_USER_DATA. ' as last_name
                ON last_name.usd_usr_id = imd_value
                AND last_name.usd_usf_id = '. $gProfileFields->getProperty('LAST_NAME', 'usf_id'). '
            LEFT JOIN '. TBL_USER_DATA. ' as first_name
                ON first_name.usd_usr_id = imd_value
                AND first_name.usd_usf_id = '. $gProfileFields->getProperty('FIRST_NAME', 'usf_id'). '
            WHERE (imf_org_id  = '. $gCurrentOrgId .'
                OR imf_org_id IS NULL)
            AND imf_name_intern = \'KEEPER\'
            ORDER BY keeper_name ASC;';
        }
        $form->addSelectBoxFromSql('filter_keeper',$selectBoxKeeperLabel, $gDb, $sql, array('defaultValue' => $getFilterKeeper , 'showContextDependentFirstEntry' => true));
 
        $form->addCheckbox('show_all', $gL10n->get('PLG_INVENTORY_MANAGER_SHOW_ALL_ITEMS'), $getShowAll, array('helpTextIdLabel' => 'PLG_INVENTORY_MANAGER_SHOW_ALL_DESC'));                           
        $form->addInput('same_side', '', '1', array('property' => HtmlForm::FIELD_HIDDEN));
        $filterNavbar->addForm($form->show());
        
        $page->addHtml($filterNavbar->show());        

        $table = new HtmlTable('adm_inventory_table', $page, $hoverRows, $datatable, $classTable);
        if ($datatable) {
            // ab Admidio 4.3 verursacht setDatatablesRowsPerPage, wenn $datatable "false" ist, folgenden Fehler:
            // "Fatal error: Uncaught Error: Call to a member function setDatatablesRowsPerPage() on null"
            $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
        }
        break;

    default:
        $table = new HtmlTable('adm_inventory_table', $page, $hoverRows, $datatable, $classTable);
        break;
}

// initialize array parameters for table and set the first column for the counter
$columnAlign  = ($getMode == 'html') ? array('left') : array('center');
$columnValues = array($gL10n->get('SYS_ABR_NO'));

$items = new CItems($gDb, $gCurrentOrgId);
$items->showFormerItems($getShowAll);
$items->readItems($gCurrentOrgId);

// headlines for columns
$columnNumber = 1;

foreach ($items->mItemFields as $itemField) {
    $imfNameIntern = $itemField->getValue('imf_name_intern');
    $columnHeader = convlanguagePIM($items->getProperty($imfNameIntern, 'imf_name'));

    if ($disableBorrowing == 1 && ($imfNameIntern === 'LAST_RECEIVER' || $imfNameIntern === 'RECEIVED_ON' || $imfNameIntern === 'RECEIVED_BACK_ON')) { 
        break;
    }

    switch ($items->getProperty($imfNameIntern, 'imf_type')) {
        case 'CHECKBOX':
        case 'RADIO_BUTTON':
        case 'GENDER':
            $columnAlign[] = 'center';
            break;

        case 'NUMBER':
        case 'DECIMAL':
            $columnAlign[] = 'right';
            break;

        default:
            $columnAlign[] = 'left';
            break;
    }

    if ($getMode == 'pdf' && $columnNumber === 1) {
        $arrValidColumns[] = $gL10n->get('SYS_ABR_NO');
    }

    if ($getMode == 'csv' || $getMode == "ods" || $getMode == 'xlsx' && $columnNumber === 1) {
        $header[$gL10n->get('SYS_ABR_NO')] = 'string';
    }

    switch ($getMode) {
        case 'csv':
        case "ods":
        case 'xlsx':
            $header[$columnHeader] = 'string';
            break;

        case 'pdf':
            $arrValidColumns[] = $columnHeader;
            break;

        case 'html':
        case 'print':
            $columnValues[] = $columnHeader;
            break;
    }

    $columnNumber++;
}

if ($getMode == 'html') {
    $columnAlign[]  = 'right';
    $columnValues[] = '&nbsp;';
    if ($datatable) {
        $table->disableDatatablesColumnsSort(array(count($columnValues)));
    }
}

if ($getMode == 'html' || $getMode == 'print') {
    $table->setColumnAlignByArray($columnAlign);
    $table->addRowHeadingByArray($columnValues);
}
elseif ($getMode == 'pdf') {
    $table->setColumnAlignByArray($columnAlign);
    $table->addTableHeader();
    $table->addRow();
    foreach ($arrValidColumns as $column) {
        $table->addColumn($column, array('style' => 'text-align:center;font-size:10;font-weight:bold;background-color:#C7C7C7;'), 'th');
    }
}

// create user object
$user = new User($gDb, $gProfileFields);

$listRowNumber = 1;

foreach ($items->items as $item) {
    $items->readItemData($item['imi_id'], $gCurrentOrgId);
    $columnValues = array();
    $strikethrough = $item['imi_former'];
    $columnNumber = 1;

    foreach ($items->mItemFields as $itemField) {
        $imfNameIntern = $itemField->getValue('imf_name_intern');

        if ($disableBorrowing == 1 && ($imfNameIntern === 'LAST_RECEIVER' || $imfNameIntern === 'RECEIVED_ON' || $imfNameIntern === 'RECEIVED_BACK_ON')) { 
            break;
        }

        if (($getFilterCategory !== '' && $imfNameIntern == 'CATEGORY' && $getFilterCategory != $items->getValue($imfNameIntern, 'database')) ||
                ($getFilterKeeper !== 0 && $imfNameIntern == 'KEEPER' && $getFilterKeeper != $items->getValue($imfNameIntern))) {
            continue 2;
        }

        if ($columnNumber === 1) {
            $columnValues[] = $listRowNumber;
        }

        $content = $items->getValue($imfNameIntern, 'database');

        if ($imfNameIntern == 'KEEPER' && strlen($content) > 0) {
            $found = $user->readDataById($content);
            if (!$found) {
                $orgName = '"' . $gCurrentOrganization->getValue('org_longname'). '"';
                $content = $gL10n->get('SYS_NOT_MEMBER_OF_ORGANIZATION',array($orgName));
            }
            else {
                if ($getMode == 'html') {
                    $content = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array('user_uuid' => $user->getValue('usr_uuid'))) . '">' . $user->getValue('LAST_NAME') . ', ' . $user->getValue('FIRST_NAME') . '</a>';
                }
                else {
                    $sql = getSqlOrganizationsUsersCompletePIM();
                    
                    $result = $gDb->queryPrepared($sql);

                    while ($row = $result->fetch()) {
                        if ($row['usr_id'] == $user->getValue('usr_id')) {
                            $content = $row['name'];
                            break;
                        }
                        $content = $user->getValue('LAST_NAME') . ', ' . $user->getValue('FIRST_NAME');
                    }        
                }
            }
        }

        if ($imfNameIntern == 'LAST_RECEIVER' && strlen($content) > 0) {
            if (is_numeric($content)) {
                $found = $user->readDataById($content);
                if ($found) {
                    if ($getMode == 'html') {
                        $content = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array('user_uuid' => $user->getValue('usr_uuid'))) . '">' . $user->getValue('LAST_NAME') . ', ' . $user->getValue('FIRST_NAME') . '</a>';
                    }
                    else {
                        $sql = getSqlOrganizationsUsersCompletePIM();
                        $result = $gDb->queryPrepared($sql);
    
                        while ($row = $result->fetch()) {
                            if ($row['usr_id'] == $user->getValue('usr_id')) {
                                $content = $row['name'];
                                break;
                            }
                            $content = $user->getValue('LAST_NAME') . ', ' . $user->getValue('FIRST_NAME');
                        }        
                    }
                }
            }
        }
        if ($items->getProperty($imfNameIntern, 'imf_type') == 'CHECKBOX') {
            $content = ($content != 1) ? 0 : 1;
            $content = ($getMode == 'csv' || $getMode == 'pdf' || $getMode == 'xlsx'|| $getMode == 'ods') ?
                ($content == 1 ? $gL10n->get('SYS_YES') : $gL10n->get('SYS_NO')) :
                $items->getHtmlValue($imfNameIntern, $content);
        }
        elseif ($items->getProperty($imfNameIntern, 'imf_type') == 'DATE') {
            $content = $items->getHtmlValue($imfNameIntern, $content);
        }
        elseif (in_array($items->getProperty($imfNameIntern, 'imf_type'), array('DROPDOWN', 'RADIO_BUTTON'))) {
            $content = $items->getHtmlValue($imfNameIntern, $content);
        }

        $columnValues[] = ($strikethrough && $getMode != 'csv' && $getMode != 'ods' && $getMode != 'xlsx') ? '<s>' . $content . '</s>' : $content;
        $columnNumber++;
    }

    if ($getMode == 'html') {
        $tempValue = '';

        // show link to view profile field change history
        if ($gSettingsManager->getBool('profile_log_edit_fields')) {
            $tempValue .= '<a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER_IM . '/items/items_history.php', array('item_id' => $item['imi_id'])) . '">
                               <i class="fas fa-history" title="' . $gL10n->get('SYS_CHANGE_HISTORY') . '"></i>
                           </a>';
        }

        // show link to edit, make former or undo former and delete item (if authorized)
        if ($authorizedForPreferences || isKeeperAuthorizedToEdit((int)$items->getValue('KEEPER', 'database'))) {
            if ($authorizedForPreferences || (isKeeperAuthorizedToEdit((int)$items->getValue('KEEPER', 'database')) && !$item['imi_former'])) {
                $tempValue .= '<a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER_IM . '/items/items_edit_new.php', array('item_id' => $item['imi_id'], 'item_former' => $item['imi_former'])) . '">
                                <i class="fas fa-edit" title="' . $gL10n->get('PLG_INVENTORY_MANAGER_ITEM_EDIT') . '"></i>
                            </a>';
            }

            if ($item['imi_former']) {
                $tempValue .= '<a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER_IM . '/items/items_delete.php', array('item_id' => $item['imi_id'], 'item_former' => $item['imi_former'], 'mode' => 4)) . '">
                                <i class="fas fa-eye" title="' . $gL10n->get('PLG_INVENTORY_MANAGER_UNDO_FORMER') . '"></i>
                            </a>';
            }

            if ($authorizedForPreferences || (isKeeperAuthorizedToEdit((int)$items->getValue('KEEPER', 'database')) && !$item['imi_former'])) {
                $tempValue .= '<a class="admidio-icon-link" href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER_IM . '/items/items_delete.php', array('item_id' => $item['imi_id'], 'item_former' => $item['imi_former'])) . '">
                                <i class="fas fa-trash-alt" title="' . $gL10n->get('PLG_INVENTORY_MANAGER_ITEM_DELETE') . '"></i>
                            </a>';
            }
        }
        $columnValues[] = $tempValue;
    }

    $showRow = ($getFilterString == '') ? true : false;

    if ($getFilterString !== '') {
        $showRowException = false;
        $filterArray = explode(',', $getFilterString);
        foreach ($filterArray as $filterString) {
            $filterString = trim($filterString);
            if (substr($filterString, 0, 1) == '-') {
                $filterString = substr($filterString, 1);
                if (stristr(implode('', $columnValues), $filterString)) {
                    $showRowException = true;
                }
            }
            if (stristr(implode('', $columnValues), $filterString)) {
                $showRow = true;
            }
        }
        if ($showRowException) {
            $showRow = false;
        }
    }

    if ($showRow) {
        switch ($getMode) {
            case 'csv':
            case 'ods':
            case 'xlsx':
                $rows[] = $columnValues;
                $strikethroughs[] = $strikethrough;
                break;

            default:
                $table->addRowByArray($columnValues, '', array('nobr' => 'true'));
                break;
            }
    }

    ++$listRowNumber;
}

if (in_array($getMode, array('csv', 'pdf', 'xlsx', 'ods'))) {
    $filename = FileSystemUtils::getSanitizedPathEntry($filename) . '.' . $getMode;
    if (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') !== false) {
        $filename = urlencode($filename);
    }
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private');
    header('Pragma: public');
}

switch ($getMode) {
    case 'pdf':
        $pdf->writeHTML($table->getHtmlTable(), true, false, true);
        $file = ADMIDIO_PATH . FOLDER_DATA . '/temp/' . $filename;
        $pdf->Output($file, 'F');
        header('Content-Type: application/pdf');
        readfile($file);
        ignore_user_abort(true);
        try {
            FileSystemUtils::deleteFileIfExists($file);
        }
        catch (\RuntimeException $exception) {
            $gLogger->error('Could not delete file!', array('filePath' => $file));
        }
        break;

    case 'csv':
    case 'ods':
    case 'xlsx':
        $contentTypes = array(
            'csv'  => 'text/csv; charset=' . $charset,
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
        );

        $writerClasses = array(
            'csv'  => Csv::class,
            'xlsx' => Xlsx::class,
            'ods'  => Ods::class,
        );

        if (!isset($contentTypes[$getMode], $writerClasses[$getMode])) {
            throw new InvalidArgumentException('Invalid mode');
        }

        $contentType = $contentTypes[$getMode];
        $writerClass = $writerClasses[$getMode];
 
        header('Content-disposition: attachment; filename="' . $filename . '"');
        header("Content-Type: $contentType");
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator($gCurrentUser->getValue('FIRST_NAME') . ' ' . $gCurrentUser->getValue('LAST_NAME'))
            ->setTitle($filename)
            ->setSubject($gL10n->get('PLG_INVENTORY_MANAGER_ITEMLIST'))
            ->setCompany($gCurrentOrganization->getValue('org_longname'))
            ->setKeywords($gL10n->get('PLG_INVENTORY_MANAGER_NAME_OF_PLUGIN') . ', ' . $gL10n->get('PLG_INVENTORY_MANAGER_ITEM'))
            ->setDescription($gL10n->get('PLG_INVENTORY_MANAGER_CREATED_WITH'));

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_keys($header), NULL, 'A1');
        $sheet->fromArray($rows, NULL, 'A2');

        if (!$getMode == 'csv') {
            foreach ($strikethroughs as $index => $strikethrough) {
                if ($strikethrough) {
                    $sheet->getStyle('A' . ($index + 2) . ':' . $sheet->getHighestColumn() . ($index + 2))
                        ->getFont()->setStrikethrough(true);
                }
            }

            formatSpreadsheet($spreadsheet, $rows, true);
        }

        $writer = new $writerClass($spreadsheet);
        $writer->save('php://output');
        break;

    case 'html':
        $page->addHtml($table->show());
        $page->show();
        break;
        
    case 'print':
        $page->addHtml($table->show());
        $page->show();
        break;
}

/**
 * Formats the spreadsheet
 *
 * @param PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
 * @param array $data
 * @param bool $containsHeadline
 */
function formatSpreadsheet($spreadsheet, $data, $containsHeadline) : void
{
    $alphabet = range('A', 'Z');
    $column = $alphabet[count($data[0])-1];

    if ($containsHeadline) {
        $spreadsheet
            ->getActiveSheet()
            ->getStyle('A1:'.$column.'1')
            ->getFill()
            ->setFillType(PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('ffdddddd');
        $spreadsheet
            ->getActiveSheet()
            ->getStyle('A1:'.$column.'1')
            ->getFont()
            ->setBold(true);
    }

    for($number = 0; $number < count($data[0]); $number++) {
        $spreadsheet->getActiveSheet()->getColumnDimension($alphabet[$number])->setAutoSize(true);
    }
    $spreadsheet->getDefaultStyle()->getAlignment()->setWrapText(true);
}
