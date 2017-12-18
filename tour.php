<?php
/**
 * Roundcube Plugin Tour
 * Plugin for adding a tour to the site.
 *
 * @version 1.2
 * @author Alexander Pushkin <san4op@icloud.com>
 * @copyright Copyright (c) 2017, Alexander Pushkin
 * @link https://github.com/san4op/roundcube_tour
 * @license GNU General Public License, version 3
 */

class tour extends rcube_plugin
{
	public $task = 'login|mail|settings';
	public $noframe = true;
	private $rc;
	private $prefs;

	function init()
	{
		$this->rc = rcube::get_instance();

		// show don't completed tour again after login
		if ($this->rc->task == 'login') {
			$this->add_hook('login_after', array($this, 'user_login'));
			return;
		}

		// get status of tour
		$this->prefs = $this->rc->user->get_prefs();
		$this->prefs = (isset($this->prefs['tour']) ? $this->prefs['tour'] : array());

		// load config
		$this->load_config();
		$introjs_theme = $this->rc->config->get('tour_introjs_theme', false);

		// load localization
		$this->add_texts('localization/', true);

		// include styles
		$this->include_stylesheet('lib/intro.js/introjs.css');
		if ($introjs_theme) {
			$this->include_stylesheet('lib/intro.js/themes/introjs-'.$introjs_theme.'.css');
		}
		$this->include_stylesheet($this->local_skin_path() . '/tour.css');

		// include scripts
		$this->include_script('lib/intro.js/intro.js');
		$this->include_script('tour.js');

		// add button
		$this->add_button(
			array(
				'name' => 'tour',
				'type' => 'link',
				'label' => 'tour.button',
				'onclick' => 'if(tour)tour(true);return false',
			),
			'topline-left'
		);

		// load config for buttons
		$taskbar_buttons = $this->rc->config->get('tour_taskbar_buttons', array('mail' => true, 'addressbook' => true, 'settings' => true));
		$toolbar_buttons = $this->rc->config->get('tour_toolbar_buttons', array());
		$settings_actions = $this->rc->config->get('tour_settings', array('preferences' => true, 'folders' => true, 'identities' => true, 'responses' => true));

		// check loaded plugins
		if (version_compare(RCMAIL_VERSION, '1.1') >= 0) {
			$plugins = $this->api->active_plugins;
		} else {
			$plugins = array_filter((array) $this->rc->config->get('plugins'));
		}
		if (!in_array('cloud_button', $plugins)) {
			$taskbar_buttons['cloud'] = false;
		}
		if (!in_array('calendar', $plugins)) {
			$taskbar_buttons['calendar'] = false;
		}
		if (!in_array('tasklist', $plugins)) {
			$taskbar_buttons['tasklist'] = false;
		}
		if (!in_array('archive', $plugins) || !$this->rc->config->get('archive_mbox')) {
			$toolbar_buttons['archive'] = false;
		}
		if (!in_array('markasjunk', $plugins) && !in_array('markasjunk2', $plugins)) {
			$toolbar_buttons['junk'] = false;
		}
		if (!in_array('managesieve', $plugins)) {
			$settings_actions['pluginmanagesieve'] = false;
			$settings_actions['pluginmanagesievevacation'] = false;
		} else {
			if (version_compare(RCMAIL_VERSION, '1.1') >= 0) {
				if (($managesieve = $this->api->get_plugin('managesieve')) instanceof managesieve) {
					if ($managesieve->load_config()) {
						$vacation_mode = (int) $this->rc->config->get('managesieve_vacation');
						if ($vacation_mode === 2) {
							$settings_actions['pluginmanagesieve'] = false;
						}
						if ($vacation_mode === 0) {
							$settings_actions['pluginmanagesievevacation'] = false;
						}
					} else {
						$settings_actions['pluginmanagesievevacation'] = false;
					}
				} else {
					$settings_actions['pluginmanagesieve'] = false;
					$settings_actions['pluginmanagesievevacation'] = false;
				}
				unset($managesieve);
			} else {
				$settings_actions['pluginmanagesieve'] = false;
				$settings_actions['pluginmanagesievevacation'] = false;
			}
		}
		if (!in_array('password', $plugins)) {
			$settings_actions['pluginpassword'] = false;
		}

		// make enviroment
		$this->api->output->set_env('tour', array(
			'welcome'			=> $this->rc->config->get('tour_welcome', true),
			'taskbar'			=> $this->rc->config->get('tour_taskbar', true),
			'taskbar_buttons'	=> $taskbar_buttons,
			'toolbar'			=> $this->rc->config->get('tour_toolbar', true),
			'toolbar_buttons'	=> $toolbar_buttons,
			'folders'			=> $this->rc->config->get('tour_folders', true),
			'quota'				=> $this->rc->config->get('tour_quota', true),
			'messages_view'		=> $this->rc->config->get('tour_messages_view', true),
			'messages_threads'	=> $this->rc->config->get('tour_messages_threads', true),
			'settings'			=> $settings_actions,
		));

		// not run again if tour already complete
		if (isset($this->prefs[$this->rc->task][$this->rc->action]) && $this->prefs[$this->rc->task][$this->rc->action] > 0) {
			return;
		}

		// register action to process complete of tour
		$this->register_action('plugin.tour_complete', array($this, 'complete'));

		// show tours
		if (!$this->rc->output->ajax_call) {
			$this->api->output->set_env('tour_run', 1);
		}
	}

	public function complete()
	{
		if (!$this->rc->output->ajax_call) {
			return;
		}

		$task = rcube_utils::get_input_value('task', rcube_utils::INPUT_POST);
		$action = rcube_utils::get_input_value('action', rcube_utils::INPUT_POST);
		$complete = (int) rcube_utils::get_input_value('complete', rcube_utils::INPUT_POST);

		if ($complete > 0) {
			if (!isset($this->prefs[$task])) {
				$this->prefs[$task] = array($action => $complete);
			}
			elseif (!isset($this->prefs[$task][$action]) || $this->prefs[$task][$action] <> 2) {
				$this->prefs[$task][$action] = $complete;
			}

			$this->rc->user->save_prefs(array('tour' => $this->prefs));
		}
	}

	public function user_login()
	{
		$prefs = $this->rc->user->get_prefs();
		$prefs = (isset($prefs['tour']) ? $prefs['tour'] : array());

		foreach ($prefs as $task => $actions) {
			foreach ($actions as $action => $complete) {
				if($complete == 1) {
					$prefs[$task][$action] = 0;
				}
			}
		}

		$this->rc->user->save_prefs(array('tour' => $prefs));
	}
}

?>