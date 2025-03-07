<?php
/**
 * ais_url_alias - URL aliases for Textpattern
 *
 * Copyright (C) 2025 Ashley Butcher (Alien Internet Services)
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
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @author	Ashley Butcher (Alien Internet Services)
 * @copyright   Copyright (C) 2025 Ashley Butcher (Alien Internet Services)
 * @license     https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License version 3
 * @version	0.2
 * @link	https://github.com/alieninternet/ais_url_alias/
 */


// Test mode of operation
switch (txpinterface) {
 case 'admin':
    ais_url_alias::newAdmin();
    break;
    
 case 'public':
    /**
     * Callback handler
     * 
     * @param  string $event
     * @param  string $step
     */
    function ais_url_alias_handler($event, $step) {
	ais_url_alias::handlePublicEvent($event);
    }
    
    // Register callback(s)
    register_callback('ais_url_alias_handler', 'textpattern');
    break;
}


/**
 * Support class
 */
class ais_url_alias
{
    /**
     * Preference defaults
     */
    const PREF_DEFAULT_CUSTOM_FIELDS = '';
    const PREF_DEFAULT_REDIRECT_PERMANENT = '0';
    const PREF_DEFAULT_SHOW_ARTICLE_CUSTOM_FIELD_VALIDITY = '1';

    
    /**
     * Preference names
     */
    const PREF_NAME_ALIASES_SORT_COL = 'ais_url_alias_aliases_sort_col';
    const PREF_NAME_ALIASES_SORT_DIR = 'ais_url_alias_aliases_sort_dir';
    const PREF_NAME_CUSTOM_FIELDS = 'ais_url_alias_custom_fields';
    const PREF_NAME_REDIRECT_PERMANENT = 'ais_url_alias_redirect_permanent';
    const PREF_NAME_SHOW_ARTICLE_CUSTOM_FIELD_VALIDITY = 'ais_url_alias_show_article_custom_field_validity';
    
    
    /**
     * Diagnostic message constants
     */
    const DIAG_ERROR = 'e';
    const DIAG_INFO = 'i';
    const DIAG_SUCCESS = 's';
    const DIAG_WARNING = 'w';
    
    
    /**
     * HTTP status codes and texts
     */
    const HTTP_FOUND = 302;
    const HTTP_FOUND_TEXT = 'Found';
    const HTTP_MOVED_PERMANENTLY = 301;
    const HTTP_MOVED_PERMANENTLY_TEXT = 'Moved permanently';

    
    /**
     * Regular expressions (validation)
     */
     // This pattern follows RFC3986, avoiding a '/' prefix, and forbidding invalid %nn escapes and disallowing #
    const REGEX_URL_ALIAS_PATH = '^[a-zA-Z0-9._~!$&\'\\(\\)*+,;=:@%\\-](?:[a-zA-Z0-9._~!$&\'\\(\\)*+,;=:@\\/\\-]|%[0-9a-fA-F]{2})*$';
    
    
    /**
     * Other constants
     */
    const MAX_CUSTOM_FIELD_NUM = 10;
    
    
    /**
     * The plugin's event as registered in Textpattern
     */
    protected string $event = __CLASS__;
    
    
    /**
     * Preferences
     */
    private ?array $customFields = null;
    private ?bool $redirectPermanent = null;
    private ?bool $showArticleCustomFieldValidity = null;
    
    
    /**
     * Constructor
     */
    private function __constructor()
    {
    }

    
    /**
     * Bounce to a new URL
     * 
     * @param  string $newURL The new URL (location) to bounce to
     */
    private function bounce($newURL) : void
    {
	global $production_status;
	
	if (isset($newURL) &&
	    (strlen($newURL) > 0)) {
	    // In debug mode, output the bounce link as debug information rather than automatically bounce
	    if ($production_status === 'debug') {
		echo '<div style="display:block;background:#f00;width:100%;font-family:monospace;color:#000;padding:1em;">[Plugin ais_url_alias] Redirect to <a href="' . $newURL . '" style="color:#000;">' . $newURL . '</a></div>';
	    } else {
		$statusCode = self::HTTP_FOUND;
		$statusText = self::HTTP_FOUND_TEXT;
	
		$this->getPrefs();
	
		// If we're live, and if configured for permanent redirection, we can return a 301 instead of a 302
		if ($production_status === 'live') {
		    $this->getPrefs();
		    if ($this->redirectPermanent) {
			$statusCode = self::HTTP_MOVED_PERMANENTLY;
			$statusText = self::HTTP_MOVED_PERMANENTLY_TEXT;
		    }
		}

		// Set the location header and response code and die gracefully
		txp_die($statusText, $statusCode, $newURL);
	    }
	}
    }
    
    
    /**
     * Check if CTE support is available in the database engine
     * 
     * @return bool True if CTE support is available, otherwise false
     */
    private function canCTE()
    {
	global $DB;
	
	// CTE support was added in MySQL 8.0 (part of SQL:1999)
	return (explode('.', $DB->version)[0] >= 8);
    }
    
    
    /**
     * Event handler for installation diagnostics
     *
     * @param  string $evt Textpattern event
     * @param  string $stp Textpattern step (action)
     */
    public function eventDiag($event, $step) : void
    {
	assert($event === 'diag');
	
	// Ensure we have the right event/step
	if ($step !== 'steps') {
	    $output = [];
	    
	    $this->getPrefs();
	    
	    // Check if custom fields have been configured
	    if (empty($this->customFields)) {
		$output[] = [self::DIAG_ERROR, 
			     ($this->t('error_no_custom_fields') . ' ' .
			      sLink(('plugin_prefs.' . $this->event), 'edit', $this->t('see_plugin_configuration')))];
	    } else {
		// These checks requires CTE support in the database engine
		if ($this->canCTE()) {
		    // Build a CTE to help flatten the article IDs and URL alias fields - we'll use it a few times
		    $cteName = rtrim(base64_encode(md5(microtime())), "=");
		    $sqlCTE = $this->sqlCTE($cteName);

		    // Ensure we have a CTE - we should, since custom fields should be configured
		    if (!empty($sqlCTE)) {
			// Check for aliases used by other articles
			$resultSet = safe_query($sqlCTE . 'SELECT DISTINCT A.ID AS ID, A.C AS C FROM ' . $cteName . 
						' AS A INNER JOIN ' . $cteName . ' AS B ON (A.C = B.C) AND (A.ID <> B.ID) ORDER BY A.ID ASC;');
			if ($resultSet &&
			    (numRows($resultSet) > 0)) {
			    while ($row = nextRow($resultSet)) {
				$output[] = [self::DIAG_ERROR,
					     $this->t('diag_error_duplicate_alias',
						      ['{article}' => eLink('article', 'edit', 'ID', $row['ID'], $row['ID']),
						       '{alias}' => htmlspecialchars($row['C'])],
						      false)];
			    }
			} else {
			    $output[] = [self::DIAG_SUCCESS, $this->t('diag_no_duplicate_aliases')];
			}
			
			// Check for invalid values
			$resultSet = safe_query($sqlCTE . 'SELECT DISTINCT ID, C FROM ' . $cteName . 
						' WHERE (C NOT REGEXP \'' . safe_escape(self::REGEX_URL_ALIAS_PATH) . '\') ORDER BY ID ASC;');
			if ($resultSet &&
			    (numRows($resultSet) > 0)) {
			    while ($row = nextRow($resultSet)) {
				$output[] = [self::DIAG_ERROR,
					     $this->t('diag_error_invalid_alias_format',
						      ['{article}' => eLink('article', 'edit', 'ID', $row['ID'], $row['ID']),
						       '{alias}' => htmlspecialchars($row['C'])],
						      false)];
			    }
			} else {
			    $output[] = [self::DIAG_SUCCESS, $this->t('diag_no_invalid_alias_format')];
			}
		    }
		}
	    }
	    
	    // Collate diagnostics results if something was created
	    if (!empty($output)) {
		$content = '';
		
		foreach ($output as $out) {
		    $cssClass = '';
		    
		    switch ($out[0])
		    {
		     case self::DIAG_ERROR:
			$cssClass = 'error';
			break;
			
		     case self::DIAG_WARNING:
			$cssClass = 'warning';
			break;
			
		     case self::DIAG_SUCCESS:
			$cssClass = 'success';
			break;
			
		     case self::DIAG_INFO:
		     default:
			$cssClass = 'information';
		    }
		    
		    $content .= tag(tag($out[1],
					'span',
					['class' => $cssClass]),
				    'li');
		}

		// Output diagnostic results HTML snippet
		if (!empty($content)) {
		    echo tag(tag((hed($this->t('diag_title'), 2) .
				  tag($content,
				      'ul')),
				 'div',
				 ['class' => 'txp-layout-1col']),
			     'div',
			     ['class' => ('txp-layout ' . $this->event)]);
		}
	    }
	}
    }
    
    
    /**
     * Event handler for CSS/JS header injection
     *
     * @param  string $event Textpattern event
     * @param  string $step  Textpattern step (action)
     * @return string        Success/failure message
     */
    public function eventHead($event, $step) : void
    {
	assert(($event === 'admin_side') &&
	       ($step === 'head_end'));
	
	global $event;
	$css = [];
	$js = [];
	$eventTokens = explode('.', $event);
	
	switch ($eventTokens[0]) {
	 // Add javascript for the article page to dynamically modify the custom fields with validation for URNs
	 case 'article':
	    $this->getPrefs();
	    if (!empty($this->customFields)) {
		// Escape regex - it needs to be inside javascript, and inside HTML. What a mess.
		$regex = str_replace('\\', '\\\\', self::REGEX_URL_ALIAS_PATH);
		$js[] = ('function ais_url_alias_(){if(jQuery){var r="' . $regex . '";');
		$cssInput = [];
		$cssInputInvalid = [];
		$cssInputAfter = [];
		$cssInputAfterInvalid = [];
		$cssInputAfterValid = [];
		foreach ($this->customFields as $customField) {
		    if (is_numeric($customField)) {
			// Add pattern to configured custom field for client-side input validation
			$js[] = ('$("#custom-' . $customField . '").attr("pattern",r);');
			
			// Add a class to the custom field for styling (if configured)
			if ($this->showArticleCustomFieldValidity) {
			    $js[] = ('$("#custom-' . $customField . '").addClass("' . $this->event . '");');
			}
		    }
		}
		
		// Finish JS - trigger on document load and DOM change
		$js[] = ('}};' .
			 '$(document).ready(ais_url_alias_);' .
			 'new (window.MutationObserver||window.WebKitMutationObserver)(ais_url_alias_).observe(document,{subtree:true,childList:true});');
		
		// Build CSS if validity should be shown
		if ($this->showArticleCustomFieldValidity) {
		    $cssElement = ('input.' . $this->event);
		    $css[] = ($cssElement . '{padding-right:1.75em;background-repeat:no-repeat;background-position:right center;background-size:1.75em;background-origin:border-box;}' .
			      $cssElement . ':invalid{text-decoration:#f00 wavy underline !important;background-image:url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22red%22%3E%3Cpath d=%22M12 10.59L5.41 4 4 5.41 10.59 12 4 18.59 5.41 20 12 13.41l6.59 6.59L20 18.59 13.41 12 20 5.41 18.59 4z%22/%3E%3C/svg%3E");}' .
			      $cssElement . ':valid{background-image:url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22green%22%3E%3Cpath d=%22M9 16.2l-4.2-4.2 1.4-1.4L9 13.4l8.8-8.8 1.4 1.4L9 16.2z%22/%3E%3C/svg%3E");}}');
		}
	    }
	    break;
	    
	 // Adjust the custom fields in the preferences to show they are used for URL aliases
	 case 'prefs':
	    $this->getPrefs();
	    foreach ($this->customFields as $customField) {
		if (is_numeric($customField)) {
		    $css[] = ('div#prefs-custom_' . $customField . '_set label:after{display:block;font-size:x-small;font-style:italic;content:"' . $this->t('prefs_used_field') . '";}');
		}
	    }
	    break;
	}
	
	if (!empty($css)) {
	    echo tag(implode($css),
		     'style',
		     ['type' => 'text/css']);
	}
	
	if (!empty($js)) {
	    echo tag(implode($js),
		     'script',
		     ['type' => 'text/javascript']);
	}
    }
    
    
    /**
     * Lifecycle event handler
     *      
     * @param  string $event Textpattern event
     * @param  string $step  Textpattern step (action)
     * @return string        Success/failure message
     */
    public function eventLifecycle($event, $step) : string
    {
	assert($event === ('plugin_lifecycle.' . $this->event));
	
	$result = '';
	
	switch ($step) {
	 case 'installed':
	    $result = $this->t('installed');
	    break;
	    
	 case 'deleted':
	    // Wipe preferences for this module to clean up the database
	    remove_pref(null, $this->event);
	    break;
	    
	 case 'disabled':
	 case 'downgraded':
	 case 'enabled':
	 case 'upgraded':
	 default:
	}
	
	return $result;
    }
    
    
    /**
     * Plugin aliases panel event handler
     *
     * @param  string $event Textpattern event
     * @param  string $step  Textpattern step (action)
     */
    public function eventPanelAliases($event, $step) : void
    {
	assert($event === $this->event);
	
	$availableSteps = [
		'list' => false,
		'multiedit' => true,
		'ais_url_alias_change_pageby' => true
	    ];
	
	switch (bouncer($step, $availableSteps) ? $step : null) {
	 case 'ais_url_alias_change_pageby':
	    $this->panelAliasesListPageby();
	    break;
	    
	 case 'multiedit':
	    $this->multieditAliases();
	    break;
	    
	 case 'list':
	 default:
	    $this->panelAliasesList();
	}
    }
    
    
    /**
     * Plugin options panel event handler
     *
     * @param  string $event Textpattern event
     * @param  string $step  Textpattern step (action)
     */
    public function eventPanelPrefs($event, $step) : void
    {
	assert($event === ('plugin_prefs.' . $this->event));
	
	$availableSteps = [
		'list' => false,
		'save' => true
	    ];

	switch (bouncer($step, $availableSteps) ? $step : null) {
	 case 'save':
	    $this->panelPrefsSave();
	    break;
	    
	 case 'list':
	 default:
	    $this->panelPrefsList();
	}
    }
    
    
    /**
     * Get plugin preferences
     */
    private function getPrefs() : void
    {
	if (!isset($this->customFields)) {
	    $customFields = get_pref(self::PREF_NAME_CUSTOM_FIELDS, self::PREF_DEFAULT_CUSTOM_FIELDS);
	    if ($customFields != '') {
		$this->customFields = explode(',', $customFields);
	    } else {
		$this->customFields = array();
	    }	    
	}
	
	if (!isset($this->redirectPermanent)) {
	    $redirectPermanent = get_pref(self::PREF_NAME_REDIRECT_PERMANENT, self::PREF_DEFAULT_REDIRECT_PERMANENT);
	    $this->redirectPermanent = ((is_numeric($redirectPermanent) && ($redirectPermanent == 1)) ? true : false);
	}
	
	if (!isset($this->showArticleCustomFieldValidity)) {
	    $showArticleCustomFieldValidity = get_pref(self::PREF_NAME_SHOW_ARTICLE_CUSTOM_FIELD_VALIDITY, self::PREF_DEFAULT_SHOW_ARTICLE_CUSTOM_FIELD_VALIDITY);
	    $this->showArticleCustomFieldValidity = ((is_numeric($showArticleCustomFieldValidity) && ($showArticleCustomFieldValidity == 1)) ? true : false);
	}
    }

    
    /**
     * Handle a public side event/callback
     * 
     * @param  string $event
     */
    static public function handlePublicEvent($event) : void
    {
	// Sanity check: We only handle the "textpattern" (= rendering) callback.
	if ($event == 'textpattern') {
	    $handler = new ais_url_alias();
	    $handler->handleRender();
	}
    }
    
    
    /**
     * Handle a public side rendering event ("textpattern" callback)
     */
    private function handleRender() : void
    {
	global $pretext;
	
	$requestURI = $pretext['request_uri'];
	$queryString = $pretext['qs'];
	
	// Sanity check - we should have the request URI at this point
	if (isset($requestURI) &&
	    ($requestURI != '')) {
	    // Clean the request URI
	    $requestURI = ltrim(explode('?', $requestURI)[0], '/');
	    
	    // Build the where clause
	    $where = $this->sqlWhere($requestURI);
	    
	    // If there's no where clause, that means the plugin is probably not configured yet (nothing to do)
	    if ($where != '') {
		// We only want exactly one live article
		$where .= ' AND (status = ' . STATUS_LIVE . ') LIMIT 1';

		// Try to find an article ID with this alternative URI
		$id = safe_field('ID', 'textpattern', $where);
		if (isset($id)) {
		    // Fetch the official permlink for that article ID
		    $newURL = permlinkurl_id($id);
			
		    // If we have a URL, let's bounce.
		    if (isset($newURL) &&
			(strlen($newURL) > 0)) {
			if (strlen($queryString) > 0) {
			    $this->bounce($newURL . '?' . $queryString);
			} else {
			    $this->bounce($newURL);
			}
		    }
		}
	    }
	}
    }

    
    /**
     * Initialise for admin
     */
    private function initAdmin() : void
    {
	// Prepare privileges
	add_privs(('plugin_prefs.' . $this->event), '1,2'); // Plugin preferences -> Publishers / Managing editors only
	add_privs($this->event, '1,2,3,4'); // URL aliases panel -> Publishers / Managing editors / Copy editor / Staff writer
	
	// Register panels
	if ($this->canCTE()) {
	    register_tab('content', $this->event, $this->t('url_aliases'));
	}
	
	// Register callbacks
        register_callback(array($this, 'eventDiag'), 'diag');
	register_callback(array($this, 'eventHead'), 'admin_side', 'head_end');
        register_callback(array($this, 'eventLifecycle'), ('plugin_lifecycle.' . $this->event));
	register_callback(array($this, 'eventPanelAliases'), $this->event);
	register_callback(array($this, 'eventPanelPrefs'), ('plugin_prefs.' . $this->event));
    }
    
    
    /**
     * Perform a multi-edit action on the aliases list
     */
    function multieditAliases()
    {
	$ok = true;
	$message = '';
	$selected = ps('selected');
	
	if (!$selected || !is_array($selected)) {
	    $this->panelAliasesList();
	    return;
	}
	
	// Clean-up selection - should be a list of key/value pairs (article ID + custom field number)
	$selected = array_map(fn($v) => array_map('assert_int', explode('_', $v)),
			      $selected);
	$selected = array_filter($selected);
	
	// Fetch valid article IDs
	if (!empty($selected)) {
	    // Fetch the multiedit method
	    $method = ps('edit_method');
	    
	    switch ($method) {
	     // Remove alias URLs
	     case 'delete':
		// Determine what custom fields are impacted
		$customFields = array_unique(array_column($selected, 1));
		$updateCount = 0;
		
		// Wipe custom field values for selected articles, per custom field
		$sql = '';
		foreach ($customFields as $customField) {
		    // Find out which article IDs are marked for this custom field
		    $articleIDs = array_unique(array_column(array_filter($selected, fn($v) => ($v[1] === $customField)), 0));
		    $ok = (safe_update('textpattern',
				       ('custom_' . $customField . ' = \'\''),
				       ('(ID in (' . join(',', $articleIDs) . '))')) &&
			   $ok);
		    
		    if ($ok) {
			$updateCount += count($articleIDs);
		    }
		}

		$message = $this->t(($ok ? 'bulk_remove_success' : 'bulk_remove_failed'),
				    ['{count}' => $updateCount]);
	        break;
	        
	     default:
	    }
	}
	
	// Reload the list
	$this->panelAliasesList($message);
    }
    
    
    /**
     * Generate the multiedit form for the URL aliases list page
     * 
     * @param  int     $page          Page number
     * @param  string  $sort          Column sorted by
     * @param  string  $dir           Sorting direction
     * @param  string  $crit          Search criterion
     * @param  string  $search_method Search method
     * @return string                 The generated multiedit HTML
     */
    function multieditAliasesForm(int $page, string $sort, string $dir, string $crit, string $searchMethod) : string
    {
	$methods = [];
	
	$methods['delete'] = $this->t('bulk_remove_alias');
	
	return tag(multi_edit($methods, $this->event, 'multiedit', $page, $sort, $dir, $crit, $searchMethod),
		   'div');
    }
    
    
    /**
     * Create a new instance for admin mode
     */
    static public function newAdmin() : void
    {
	$handler = new ais_url_alias();
	$handler->initAdmin();
    }
    
    
    /**
     * URL aliases panel - list mode
     * 
     * @param $message  Message to output
     */
    private function panelAliasesList($message = '') : void
    {
	$title = $this->t('url_aliases');
	pagetop($title, $message);

	// Table fields
	$tableFields = ['ID', 'Title', 'C'];
	
	// Get page query values
	extract(gpsa(['page', 'sort', 'dir', 'crit', 'search_method']));
	
	// Prepare the CTE we'll be using to collapse all configured custom fields together
	$cteName = rtrim(base64_encode(md5(microtime())), "=");
	$sqlCTE = $this->sqlCTE($cteName);
	
	// Sort field as defined, by preference, or default
	if (empty($sort)) {
	    $sort = get_pref(self::PREF_NAME_ALIASES_SORT_COL, 'C');
	} else {
	        if (!in_array($sort, $tableFields)) {
		    $sort = self::PREF_DEFAULT_ALIASES_SORT_COL;
		}
	    
	    set_pref(self::PREF_NAME_ALIASES_SORT_COL, $sort, $this->event, PREF_HIDDEN, '', 0, PREF_PRIVATE);
	}
	
	// Sort direction by preference or by default
	if (empty($dir)) {
	    $dir = get_pref(self::PREF_NAME_ALIASES_SORT_DIR, 'DESC');
	} else {
	    $dir = ($dir === 'DESC' ? 'DESC' : 'ASC');
	    set_pref(self::PREF_NAME_ALIASES_SORT_DIR, $dir, $this->event, PREF_HIDDEN, '', 0, PREF_PRIVATE);
	}
	
	// Toggle direction is the opposite of the current direction :)
	$toggleDir = (($dir === 'DESC') ? 'ASC' : 'DESC');

	// Build SQL sort string
	switch ($sort) {
	 // Article ID from CTE
	 case 'ID':
	    $sqlSort = "A.ID $dir";
	    break;
	 
	 // Alias from CTE
	 case 'C':
	    $sqlSort = "A.$sort $dir, A.ID DESC";
	    break;
	 
	 // Fields from article table
	 case 'Title':
	 default:
	        $sqlSort = "B.$sort $dir, A.ID DESC";
	}

	// Build filtering
	$search = new \Textpattern\Search\Filter($this->event, [
	        'ID' => [
		    'column' => 'A.ID',
		    'label' => gTxt('article'),
		    'type' => 'integer'
		],
		'Title' => [
		    'column' => 'B.Title',
		    'label' => gTxt('title')
		],
		'C' => [
		    'column' => 'A.C',
		    'label' => $this->t('url_alias')
		]
	    ]);
	list($sqlCriteria, $crit, $searchMethod) = $search->getFilter(['ID' => ['can_list' => true]]);
	
	// Build SQL 'from' chunk
	$sqlFrom = ("$cteName AS A INNER JOIN " . safe_pfx_j('textpattern') . ' AS B ON (A.ID = B.ID)');
	
	// Calculate total
	if ($crit) {
	    // Include the full join since the criteria may include fields from any table
	    $total = getThing("$sqlCTE SELECT COUNT(*) FROM $sqlFrom" .
			      (empty($crit) ? '' : " WHERE $sqlCriteria"));
	} else {
	    // Simpler version if there's no criteria, without the join or the criteria
	    $total = getThing("$sqlCTE SELECT COUNT(*) FROM $cteName");
	}

	// Build the search block
	$searchRenderOptions = ['placeholder' => 'ais_url_alias_search_aliases'];
	$searchBlock = n.tag($search->renderForm('list',
						 $searchRenderOptions),
			     'div',
			     ['class' => 'txp-layout-4col-3span',
			      'id' => ($this->event . '_control')]);
	
	// Build the paginator
	$paginator = new \Textpattern\Admin\Paginator($this->event);
	$limit = $paginator->getLimit();
	list($page, $offset, $numPages) = pager($total, $limit, $page);

	// Build the content block
	$contentBlock = '';
	if ($total <= 0) {
	    $contentBlock .= graf((span(null, ['class' => 'ui-icon ui-icon-info']) . 
				   ' ' .
				   (empty($crit) ? $this->t('no_aliases_configured') : gTxt('no_results_found'))),
				  ['class' => 'alert-block information']);
	} else {
	    // Fetch the rows to display
	    $resultSet = safe_query("$sqlCTE SELECT A.*, B.Title FROM $sqlFrom WHERE $sqlCriteria ORDER BY $sqlSort LIMIT $offset, $limit");
	    
	    // Ensure we got something back
	    if ($resultSet && 
		(numRows($resultSet) > 0)) {
		// Start the multiedit form and the table header
		$contentBlock .= (n.tag_start('form', ['class'  => 'multi_edit_form',
						       'id'     => 'ais_url_alias_aliases_form',
						       'name'   => 'longform',
						       'method' => 'post',
						       'action' => 'index.php']) .
				  n.tag_start('div', ['class'      => 'txp-listtables',
						      'tabindex'   => 0,
						      'aria-label' => gTxt('list')]) .
				  n.tag_start('table', ['class' => 'txp-list']) .
				  n.tag_start('thead') .
				  tr(hCell(fInput('checkbox', 'select_all', 0, '', '', '', '', '', 'select_all'),
					   '', 
					   (' class="txp-list-col-multi-edit" scope="col" title="' . gTxt('toggle_all_selected') . '"')) .
				     column_head('article', 'ID', $this->event, true, $toggleDir, $crit, $searchMethod,
						 ((($sort === 'ID') ? "$dir " : '') . 'txp-list-col-id')) .
				     column_head('title', 'Title', $this->event, true, $toggleDir, $crit, $searchMethod,
						 ((($sort === 'Title') ? "$dir " : '') . 'txp-list-col-title')) .
				     column_head('ais_url_alias_url_alias', 'C', $this->event, true, $toggleDir, $crit, $searchMethod,
						 ((($sort === 'C') ? $dir : '')))) .
				  n.tag_end('thead') .
				  n.tag_start('tbody'));
		
		// Loop through row
		while ($row = nextRow($resultSet)) {
		    // Edit URL links back to the article where the URL alias can be edited
		    $urlEditArticle = ['event' => 'article', 'step' => 'edit', 'ID' => $row['ID']];
		    
		    // Add this row to a content block
		    $contentBlock .= (tr(td(fInput('checkbox', 'selected[]', ($row['ID'] . '_' . $row['N']),
					    '', 
					    'txp-list-col-multi-edit') .
					 hCell(href($row['ID'], $urlEditArticle, (' title="' . gTxt('edit') . '"')), 
					       '', 
					       ' class="txp-list-col-id" scope="row"') .
					 td(href(txpspecialchars($row['Title']), $urlEditArticle, (' title="' . gTxt('edit') . '"')), 
					    '', 
					    'txp-list-col-title') .
					 td($row['C']))));
		}
	    
	        // Finish the table and the content block
		$contentBlock .= (n.tag_end('tbody') .
				  n.tag_end('table') .
				  n.tag_end('div') .
				  $this->multieditAliasesForm($page, $sort, $dir, $crit, $searchMethod) .
				  tInput() .
				  n.tag_end('form'));
	    }
	}
	
	// Build the pagination block
	$pageBlock = ($paginator->render() .
		      nav_form($this->event, $page, $numPages, $sort, $dir, $crit, $searchMethod, $total, $limit));

	// Render out the table
	$table = new \Textpattern\Admin\Table($this->event);
	echo $table->render(compact('title', 'total', 'crit'), $searchBlock, null, $contentBlock, $pageBlock);
    }
    
    
    /**
     * Change URL aliases pagination
     * 
     * @param $message  Message to output
     */
    private function panelAliasesListPageby() : void
    {
        \Txp::get('\Textpattern\Admin\Paginator')->change();
	$this->panelAliasesList();
    }
    
    
    /**
     * Plugin preferences panel - list mode
     * 
     * @param $message  Message to output
     */
    private function panelPrefsList($message = '') : void
    {
	$title = $this->t('prefs_title');
	pagetop($title, $message);
	$pageContent = '';
	
	// Build the page title
	$titleContent =
	  tag(hed($title, 1, ['class' => 'txp-heading']),
	      'div',
	      ['class' => 'txp-layout-1col']);
	
	// Fetch/default preferences
	$this->getPrefs();

	// Behaviour fields
	$formContentBehaviour =
	  (inputLabel(self::PREF_NAME_REDIRECT_PERMANENT,
		      selectInput(self::PREF_NAME_REDIRECT_PERMANENT,
				  ['0' => $this->t('pref_redirect_type_temporary'),
				   '1' => $this->t('pref_redirect_type_permanent')],
				  ($this->redirectPermanent ? '1' : '0')), 
		      'ais_url_alias_pref_redirect_type',
		      'ais_url_alias_help_pref_redirect_type') .
	   inputLabel(self::PREF_NAME_SHOW_ARTICLE_CUSTOM_FIELD_VALIDITY,
		      onoffRadio(self::PREF_NAME_SHOW_ARTICLE_CUSTOM_FIELD_VALIDITY,
				 ($this->showArticleCustomFieldValidity ? '1' : '0')),
		      'ais_url_alias_pref_show_article_custom_field_validity',
		      'ais_url_alias_help_pref_show_article_custom_field_validity'));

	// Custom field checkbox fields
	$formContentCustom = '';
	for ($i = 1; $i <= self::MAX_CUSTOM_FIELD_NUM; ++$i) {
	    $fieldName = (self::PREF_NAME_CUSTOM_FIELDS . '_' . $i);
	    $formContentCustom .=
	      inputLabel($fieldName,
			 checkbox($fieldName, 1, in_array($i, $this->customFields)),
			 ('ais_url_alias_pref_custom_' . $i));
	}
	
	// Build behaviour group
	$formTitleBehaviour = $this->t('prefs_title_behaviour');
	$formIDBehaviour = 'ais_url_alias_pref_group_behaviour';
	$formTabBehaviour =
	  tag(href($formTitleBehaviour,
		   ('#' . $formIDBehaviour),
		   ['data-txp-pane' => $formIDBehaviour,
		    'data-txp-token' => md5($formIDBehaviour . form_token() . get_pref('blog_uid'))]),
	      'li');
	$formGroupBehaviour =
	  tag((hed($formTitleBehaviour, 2, ['id' => 'ais_url_alias_pref_group_behaviour-label']) .
	       $formContentBehaviour), 
	      'section',
	      ['class' => 'txp-tabs-vertical-group',
	       'id' => $formIDBehaviour,
	       'aria-labelledby' => 'ais_url_alias_pref_group_behaviour-label']);
	
	// Build custom fields group
	$formTitleCustom = $this->t('prefs_title_custom');
	$formIDCustom = 'ais_url_alias_pref_group_custom';
	$formTabCustom = 
	  tag(href($formTitleCustom,
		   ('#' . $formIDCustom),
		   ['data-txp-pane' => $formIDCustom,
		    'data-txp-token' => md5($formIDCustom . form_token() . get_pref('blog_uid'))]),
	      'li');
	$formGroupCustom = 
	  tag((hed($formTitleCustom, 2, ['id' => 'ais_url_alias_pref_group_custom-label']) .
	       graf($this->t('prefs_help_custom')) .
	       $formContentCustom), 
	      'section', 
	      ['class' => 'txp-tabs-vertical-group',
	       'id' => $formIDCustom,
	       'aria-labelledby' => 'ais_url_alias_pref_group_custom-label']);
	
	// Collate tab section (including the save button)
	$formTabContent =
	  tag((wrapGroup('ais_url_alias_prefs',
			 tag(($formTabBehaviour .
			      $formTabCustom),
			     'ul',
			     ['class' => 'switcher-list']),
			 'tab_preferences') .
	       graf(fInput('submit', 'Submit', gTxt('save'), 'publish'), array('class' => 'txp-save'))),
	      'div',
	      ['class' => 'txp-layout-4col-alt']);
	
	// Collate group content
	$formGroupContent =
	  tag(($formGroupBehaviour .
	       $formGroupCustom),
	      'div',
	      ['class' => 'txp-layout-4col-3span']);
	
	// Collate the form content, adding event and step hidden fields
	$formContent =
	  ($formTabContent .
	   $formGroupContent .
	   eInput('plugin_prefs.' . $this->event) .
	   sInput('save'));
	
	// Wrap the content in an outer container and a form
	$pageContent = 
	  form(tag(($titleContent . $formContent), 'div', ['class' => 'txp-layout']), 
	       '',
	       '',
	       'post', 
	       'txp-prefs', 
	       '', 
	       ($this->event . '_prefs_form'));
	
	// Output the page
	echo $pageContent;
    }
    
    
    /**
     * Plugin preferences panel - save mode
     * 
     * Valid values are saved locally so if there is an error they don't get reset as the UI is reloaded
     */
    private function panelPrefsSave() : void
    {
	$ok = true;
	$message = '';
	
	// Get existing preferences
	$this->getPrefs();
	$oldCustomFields = $this->customFields;
	$oldRedirectPermament = $this->redirectPermanent;
	$oldShowArticleCustomFieldValidity = $this->showArticleCustomFieldValidity;

	// Fetch custom field toggles
	$newCustomFields = [];
	for ($i = 1; $i <= self::MAX_CUSTOM_FIELD_NUM; ++$i) {
	    $postField = gps(self::PREF_NAME_CUSTOM_FIELDS . '_' . $i);
	    // Is this one here and set?
	    if (isset($postField) &&
		!empty($postField) &&
		is_numeric($postField) &&
		($postField == '1')) {
		$newCustomFields[] = $i;
	    }
	}
	$this->customFields = $newCustomFields;
	
	// Permanent redirect flag
	$postField = gps(self::PREF_NAME_REDIRECT_PERMANENT);
	if (isset($postField)) {
	    // Validate
	    if (is_numeric($postField) &&
		($postField >= 0) &&
		($postField <= 1)) {
		$this->redirectPermanent = ($postField == 1);
	    } else {
		$ok = false;
		$message .= tag($this->t('error_invalid_redirect_type'), 'p');
	    }
	}
	
	// Show custom field validity on article flag
	$postField = gps(self::PREF_NAME_SHOW_ARTICLE_CUSTOM_FIELD_VALIDITY);
	if (isset($postField)) {
	    // Validate
	    if (is_numeric($postField) &&
		($postField >= 0) &&
		($postField <= 1)) {
		$this->showArticleCustomFieldValidity = ($postField == 1);
	    }
	}
	
	// If everything is okay, we can save
	if ($ok) {
	    if ($this->customFields != $oldCustomFields) {
		set_pref(self::PREF_NAME_CUSTOM_FIELDS, implode(',', $this->customFields), $this->event, PREF_HIDDEN, '');
	    }
	    
	    if ($this->redirectPermanent != $oldRedirectPermanent) {
		set_pref(self::PREF_NAME_REDIRECT_PERMANENT, $this->redirectPermanent, $this->event, PREF_HIDDEN, '');
	    }

	    if ($this->showArticleCustomFieldValidity != $oldShowArticleCustomFieldValidity) {
		set_pref(self::PREF_NAME_SHOW_ARTICLE_CUSTOM_FIELD_VALIDITY, $this->showArticleCustomFieldValidity, $this->event, PREF_HIDDEN, '');
	    }
	    
	    $message = gTxt('preferences_saved');
	}
	
	$this->panelPrefsList($message);
    }
    
    
    /**
     * Build an SQL CTE to combine all custom fields together
     * 
     * @param  string $cteName The name of the CTE (best to use something random)
     * @return string          The CTE clause
     */
    private function sqlCTE($cteName) : string
    {
	$cte = '';
	
	$this->getPrefs();
	
	foreach ($this->customFields as $customField) {
	    if (is_numeric($customField)) {
		if (!empty($sql)) {
		    $cte .= ' UNION ALL ';
		}

		// Select this custom field from the articles table. Note the 'N' column is the custom field ID for later reference
		$cte .= ('SELECT ID,' . $customField . ',custom_' . $customField . ' FROM ' . safe_pfx('textpattern') . ' WHERE (custom_' . $customField . ' <> \'\')');
	    }
	}

	if (!empty($cte)) {
	    $cte = ('WITH ' . $cteName . ' (ID,N,C) AS (' . $cte . ') ');
	}
	
	return $cte;
    }
    
    
    /**
     * Build an SQL WHERE clause for the given URL
     * 
     * @param  string $aliasURL   The alias URL
     * @return string             The WHERE clause
     */
    private function sqlWhere($aliasURL) : string
    {
	$where = '';
	
	// Sanity check - Ensure we are passed a URL
	if (strlen($aliasURL) > 0) {
	    // Fetch preferences
	    $this->getPrefs();
	    
	    // If no custom fields have been configured, there's no custom field to check :)
	    if (isset($this->customFields) &&
		!empty($this->customFields)) {
		// Build a WHERE clause for the query - first one found wins
		foreach ($this->customFields as $customField) {
		    // Sanity check the field value
		    if (is_numeric($customField) &&
			($customField > 0) &&
			($customField <= self::MAX_CUSTOM_FIELD_NUM)) {
			if (strlen($where) > 0) {
			    $where .= ' OR ';
			}
			
			$where .= "(custom_$customField = '" . safe_escape($aliasURL) . '\')';
		    }
		}
	    }
	}
	
	// If we have a where clause, wrap it up as one clause
	if (!empty($where)) {
	    $where = ('(' . $where . ')');
	}
	
	return $where;
    }
    
    
    /**
     * Fetch translated text based on the provided key
     * 
     * @param  string $key    Text key
     * @param  array  $attr   Text substitutions
     * @param  bool   $escape HTML escape the attributes
     * @return string         The translated text
     */
    private function t(string $key, array $attr = [], bool $escape = true) : string
    {
	return gTxt(('ais_url_alias_' . $key), $attr, ($escape ? 'html' : ''));
    }
}
