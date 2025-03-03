<?php
/**
 * ais_url_alias - URL aliases for Textpattern
 *
 * Technically it's a URI alias plugin since it only really impacts the path bit
 * of the URL, but everyone calls the functionality 'URL alias' for some reason, 
 * which makes the nerd in me very sad.
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
 * @version	0.1
 * @link	https://github.com/alieninternet/ais_url_alias/
 */


// Test mode of operation
switch (txpinterface) {
 case 'admin':
    // TODO: This
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
    const PREF_DEFAULT_CUSTOM_FIELDS = '1'; // TODO: Empty this out after testing
    const PREF_DEFAULT_REDIRECT_PERMANENT = '0';

    
    /**
     * Preference names
     */
    const PREF_NAME_CUSTOM_FIELDS = 'ais_url_alias_custom_fields';
    const PREF_NAME_REDIRECT_PERMANENT = 'ais_url_alias_redirect_permanent';
    
    
    /**
     * HTTP status codes and texts
     */
    const HTTP_FOUND = 302;
    const HTTP_FOUND_TEXT = 'Found';
    const HTTP_MOVED_PERMANENTLY = 301;
    const HTTP_MOVED_PERMANENTLY_TEXT = 'Moved permanently';
    
    
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

    
    /**
     * Constructor
     */
    private function __constructor()
    {
    }

    
    /**
     * Bounce to a new URL
     * 
     * @param  string $newURL   The new URL (location) to bounce to
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

		// Set the location header and response code
		txp_status_header($statusCode . ' ' . $statusText);
		header('Location: ' . $newURL);
		
		// End rendering here
		ob_flush();
		exit();
	    }
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
	    $redirectPermament = get_pref(self::PREF_NAME_REDIRECT_PERMANENT, self::PREF_DEFAULT_REDIRECT_PERMANENT);
	    $this->redirectPermament = ((is_numeric($redirectPermanent) && ($redirectPermament == 1)) ? true : false);
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
	
	// Sanity check - we should have the request URI at this point
	if (isset($requestURI) &&
	    ($requestURI != '')) {
	    // Clean the request URI
	    $requestURI = ltrim($requestURI, '/');
	    
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
			$this->bounce($newURL);
		    }
		}
	    }
	}
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
	if ($where) {
	    $where = ('(' . $where . ')');
	}
	
	return $where;
    }
}