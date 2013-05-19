<?php
/**
 * @copyright  Copyright (C) 2012 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Tracker\Components\Users\View\Users;

use Joomla\Tracker\Components\Users\Model\UsersModel;
use Joomla\Tracker\View\AbstractTrackerHtmlView;

/**
 * Users view class for the Users component
 *
 * @since  1.0
 */
class UsersHtmlView extends AbstractTrackerHtmlView
{
	/**
	 * Redefine the model so the correct type hinting is available.
	 *
	 * @var     UsersModel
	 * @since   1.0
	 */
	protected $model;

	/**
	 * Method to render the view.
	 *
	 * @return  string  The rendered view.
	 *
	 * @since   1.0
	 */
	public function render()
	{
		$this->renderer->set('items', $this->model->getItems());

		return parent::render();
	}
}
