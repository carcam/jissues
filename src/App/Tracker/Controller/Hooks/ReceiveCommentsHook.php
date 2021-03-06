<?php
/**
 * Part of the Joomla Tracker's Tracker Application
 *
 * @copyright  Copyright (C) 2012 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace App\Tracker\Controller\Hooks;

use App\Projects\TrackerProject;
use App\Tracker\Controller\AbstractHookController;
use App\Tracker\Model\IssueModel;
use App\Tracker\Table\ActivitiesTable;
use App\Tracker\Table\IssuesTable;

use Joomla\Date\Date;

/**
 * Controller class receive and inject issue comments from GitHub
 *
 * @since  1.0
 */
class ReceiveCommentsHook extends AbstractHookController
{
	/**
	 * The type of hook being executed
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $type = 'comments';

	/**
	 * Prepare the response.
	 *
	 * @return  mixed
	 *
	 * @since   1.0
	 */
	protected function prepareResponse()
	{
		$commentId = null;

		try
		{
			// Check to see if the comment is already in the database
			$commentId = $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName('activities_id'))
					->from($this->db->quoteName('#__activities'))
					->where($this->db->quoteName('gh_comment_id') . ' = ' . (int) $this->hookData->comment->id)
			)->loadResult();
		}
		catch (\RuntimeException $e)
		{
			$this->logger->error('Error checking the database for comment ID', ['exception' => $e]);
			$this->getContainer()->get('app')->close();
		}

		// If the item is already in the database, update it; else, insert it
		if ($commentId)
		{
			$this->updateComment($commentId);
		}
		else
		{
			$this->insertComment();
		}

		$this->response->message = 'Hook data processed successfully.';
	}

	/**
	 * Method to insert data for a comment from GitHub
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.0
	 */
	protected function insertComment()
	{
		// If we don't have an ID, we need to insert the issue and all comments, or we only insert the newly received comment
		if (!$this->checkIssueExists((int) $this->hookData->issue->number))
		{
			$this->insertIssue();

			$comments = $this->github->issues->comments->getList(
				$this->project->gh_user, $this->project->gh_project, $this->hookData->issue->number
			);

			foreach ($comments as $comment)
			{
				// Add the comment
				$this->addActivityEvent(
					'comment',
					$comment->created_at,
					$comment->user->login,
					$this->project->project_id,
					$this->hookData->issue->number,
					$comment->id,
					$this->parseText($comment->body),
					$comment->body
				);
			}
		}
		else
		{
			// Add the comment
			$this->addActivityEvent(
				'comment',
				$this->hookData->comment->created_at,
				$this->hookData->comment->user->login,
				$this->project->project_id,
				$this->hookData->issue->number,
				$this->hookData->comment->id,
				$this->parseText($this->hookData->comment->body),
				$this->hookData->comment->body
			);

			// Pull the user's avatar if it does not exist
			$this->pullUserAvatar($this->hookData->comment->user->login);
		}

		try
		{
			// Get a table object for the new record to process in the event listeners
			$issueTable = (new IssuesTable($this->db))->load(
				[
					'issue_number' => $this->hookData->issue->number,
					'project_id'   => $this->project->project_id,
				]
			);

			$this->triggerEvent('onCommentAfterCreate', ['table' => $issueTable]);
		}
		catch (\Exception $e)
		{
			$this->logger->error('Error loading the database for comment ' . $this->hookData->issue->number, ['exception' => $e]);
		}

		// Store was successful, update status
		$this->logger->info(
			sprintf(
				'Added GitHub comment %s/%s #%d to the tracker.',
				$this->project->gh_user,
				$this->project->gh_project,
				$this->hookData->comment->id
			)
		);

		return true;
	}

	/**
	 * Method to insert data for an issue from GitHub
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected function insertIssue()
	{
		// Prepare the dates for insertion to the database
		$dateFormat = $this->db->getDateFormat();

		$data = [];
		$data['issue_number']    = $this->hookData->issue->number;
		$data['title']           = $this->hookData->issue->title;
		$data['description']     = $this->parseText($this->hookData->issue->body);
		$data['description_raw'] = $this->hookData->issue->body;
		$data['status']          = ($this->hookData->issue->state) == 'open' ? 1 : 10;
		$data['opened_date']     = (new Date($this->hookData->issue->created_at))->format($dateFormat);
		$data['opened_by']       = $this->hookData->issue->user->login;
		$data['modified_date']   = (new Date($this->hookData->issue->updated_at))->format($dateFormat);
		$data['modified_by']     = $this->hookData->sender->login;
		$data['project_id']      = $this->project->project_id;
		$data['build']           = $this->hookData->repository->default_branch;

		// Add the closed date if the status is closed
		if ($this->hookData->issue->closed_at)
		{
			$data['closed_date'] = (new Date($this->hookData->issue->closed_at))->format($dateFormat);
			$data['closed_by']   = $this->hookData->sender->login;
		}

		// If the title has a [# in it, assume it's a JoomlaCode Tracker ID
		if (preg_match('/\[#([0-9]+)\]/', $this->hookData->issue->title, $matches))
		{
			$data['foreign_number'] = $matches[1];
		}
		// If the body has tracker_item_id= in it, that is a JoomlaCode Tracker ID
		elseif (preg_match('/tracker_item_id=([0-9]+)/', $this->hookData->issue->body, $matches))
		{
			$data['foreign_number'] = $matches[1];
		}

		// Process labels for the item
		$data['labels'] = $this->processLabels($this->hookData->issue->number);

		try
		{
			(new IssueModel($this->db))
				->setProject(new TrackerProject($this->db, $this->project))
				->add($data);
		}
		catch (\Exception $e)
		{
			$this->logger->error(
				sprintf(
					'Error adding GitHub issue %s/%s #%d to the tracker',
					$this->project->gh_user,
					$this->project->gh_project,
					$this->hookData->issue->number
				),
				['exception' => $e]
			);

			$this->getContainer()->get('app')->close();
		}

		// Get a table object for the new record to process in the event listeners
		$table = (new IssuesTable($this->db))
			->load($this->db->insertid());

		$this->triggerEvent('onCommentAfterCreateIssue', ['table' => $table]);

		// Pull the user's avatar if it does not exist
		$this->pullUserAvatar($this->hookData->issue->user->login);

		// Add a close record to the activity table if the status is closed
		if ($this->hookData->issue->closed_at)
		{
			$this->addActivityEvent(
				'close',
				$data['closed_date'],
				$this->hookData->sender->login,
				$this->project->project_id,
				$this->hookData->issue->number
			);
		}

		// Store was successful, update status
		$this->logger->info(
			sprintf(
				'Added GitHub issue %s/%s #%d (Database ID #%d) to the tracker.',
				$this->project->gh_user,
				$this->project->gh_project,
				$this->hookData->issue->number,
				$table->id
			)
		);
	}

	/**
	 * Method to update data for an issue from GitHub
	 *
	 * @param   integer  $id  The comment ID
	 *
	 * @return  boolean  True on success
	 *
	 * @since   1.0
	 */
	protected function updateComment($id)
	{
		switch ($this->hookData->action)
		{
			case 'deleted':
				try
				{
					(new ActivitiesTable($this->db))
						->delete($id);
				}
				catch (\Exception $e)
				{
					$this->setStatusCode($e->getCode());

					$logMessage = sprintf(
						'Error deleting GitHub comment %s/%s #%d from the tracker',
						$this->project->gh_user,
						$this->project->gh_project,
						$id
					);

					$this->response->error = $logMessage . ': ' . $e->getMessage();
					$this->logger->error($logMessage, ['exception' => $e]);

					return false;
				}

				// Delete was successful, update status
				$this->logger->info(
					sprintf(
						'Deleted comment %s/%s #%d from the tracker.',
						$this->project->gh_user,
						$this->project->gh_project,
						$id
					)
				);

				return true;

			// Treat an edit the same as we do a general update
			case 'edited':
			default:
				// Only update fields that may have changed, there's no API endpoint to show that so make some guesses
				$data = [
					'activities_id' => $id,
					'text'          => $this->parseText($this->hookData->comment->body),
					'text_raw'      => $this->hookData->comment->body,
					'updated_at'    => (new Date($this->hookData->comment->updated_at))->format($this->db->getDateFormat()),
				];

				try
				{
					(new ActivitiesTable($this->db))
						->load(['activities_id' => $id])
						->save($data);
				}
				catch (\Exception $e)
				{
					$this->setStatusCode($e->getCode());

					$logMessage = sprintf(
						'Error updating GitHub comment %s/%s #%d in the tracker',
						$this->project->gh_user,
						$this->project->gh_project,
						$id
					);

					$this->response->error = $logMessage . ': ' . $e->getMessage();
					$this->logger->error($logMessage, ['exception' => $e]);

					return false;
				}

				try
				{
					$issueTable = (new IssuesTable($this->db))->load(
						[
							'issue_number' => $this->hookData->issue->number,
							'project_id'   => $this->project->project_id,
						]
					);

					$this->triggerEvent('onCommentAfterUpdate', ['table' => $issueTable]);
				}
				catch (\Exception $e)
				{
					$this->logger->error('Error loading the database for issue ' . $this->hookData->issue->number, ['exception' => $e]);
				}

				// Store was successful, update status
				$this->logger->info(
					sprintf(
						'Updated comment %s/%s #%d to the tracker.',
						$this->project->gh_user,
						$this->project->gh_project,
						$id
					)
				);

				return true;
		}
	}
}
