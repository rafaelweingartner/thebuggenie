<?php

    namespace thebuggenie\modules\vcs_integration;

    use TBGEvent,
        TBGContext,
        TBGUser,
        TBGIssue,
        TBGSettings,
        TBGActionComponent,
        TBGUsersTable,
        TBGProject,
        TBGStatus,
        TBGResolution,
        thebuggenie\modules\vcs_integration\entities\File,
        thebuggenie\modules\vcs_integration\entities\b2db\Files,
        thebuggenie\modules\vcs_integration\entities\IssueLink,
        thebuggenie\modules\vcs_integration\entities\b2db\IssueLinks,
        thebuggenie\modules\vcs_integration\entities\Commit,
        thebuggenie\modules\vcs_integration\entities\b2db\Commits;

/**
     * Module class, vcs_integration
     *
     * @author Philip Kent <kentphilip@gmail.com>
     * @version 3.1
     * @license http://opensource.org/licenses/MPL-2.0 Mozilla Public License 2.0 (MPL 2.0)
     * @package thebuggenie
     * @subpackage vcs_integration
     */

    /**
     * Module class, vcs_integration
     *
     * @package thebuggenie
     * @subpackage vcs_integration
     *
     * @Table(name="\TBGModulesTable")
     */
    class Vcs_integration extends \TBGModule
    {

        const VERSION = '2.0';

        const MODE_DISABLED = 0;
        const MODE_ISSUECOMMITS = 1;
        const WORKFLOW_DISABLED = 0;
        const WORKFLOW_ENABLED = 1;
        const ACCESS_DIRECT = 0;
        const ACCESS_HTTP = 1;
        const NOTIFICATION_COMMIT_MENTIONED = 'commit_mentioned';

        protected $_longname = 'VCS Integration';
        protected $_description = 'Allows details from source code checkins to be displayed in The Bug Genie. Configure in each project\'s settings.';
        protected $_module_config_title = 'VCS Integration';
        protected $_module_config_description = 'Configure repository settings for source code integration';
        protected $_has_config_settings = false;

        protected function _initialize()
        {

        }

        protected function _install($scope)
        {

        }

        protected function _loadFixtures($scope)
        {
            Commits::getTable()->createIndexes();
            Files::getTable()->createIndexes();
            IssueLinks::getTable()->createIndexes();
        }

        protected function _addListeners()
        {
            TBGEvent::listen('core', 'project_sidebar_links', array($this, 'listen_project_links'));
            TBGEvent::listen('core', 'breadcrumb_project_links', array($this, 'listen_breadcrumb_links'));
            TBGEvent::listen('core', 'get_backdrop_partial', array($this, 'listen_getcommit'));
            TBGEvent::listen('core', 'viewissue_left_after_attachments', array($this, 'listen_viewissue_panel'));
            TBGEvent::listen('core', 'config_project_tabs_other', array($this, 'listen_projectconfig_tab'));
            TBGEvent::listen('core', 'config_project_panes', array($this, 'listen_projectconfig_panel'));
            TBGEvent::listen('core', 'project_header_buttons', array($this, 'listen_projectheader'));
            TBGEvent::listen('core', '_notification_view', array($this, 'listen_notificationview'));
            TBGEvent::listen('core', 'TBGNotification::getTarget', array($this, 'listen_TBGNotification_getTarget'));
        }

        protected function _uninstall()
        {
            if (TBGContext::getScope()->getID() == 1)
            {
                Commits::getTable()->drop();
                Files::getTable()->drop();
                IssueLinks::getTable()->drop();
            }
            parent::_uninstall();
        }

        public function getRoute()
        {
            return TBGContext::getRouting()->generate('vcs_integration');
        }

        public function hasProjectAwareRoute()
        {
            return false;
        }

        public function listen_sidebar_links(TBGEvent $event)
        {
            if (TBGContext::isProjectContext())
            {
                TBGActionComponent::includeComponent('vcs_integration/menustriplinks', array('project' => TBGContext::getCurrentProject(), 'module' => $this, 'submenu' => $event->getParameter('submenu')));
            }
        }

        public function listen_breadcrumb_links(TBGEvent $event)
        {
            $event->addToReturnList(array('url' => TBGContext::getRouting()->generate('vcs_commitspage', array('project_key' => TBGContext::getCurrentProject()->getKey())), 'title' => TBGContext::getI18n()->__('Commits')));
        }

        public function listen_project_links(TBGEvent $event)
        {
            $event->addToReturnList(array('url' => TBGContext::getRouting()->generate('vcs_commitspage', array('project_key' => TBGContext::getCurrentProject()->getKey())), 'title' => TBGContext::getI18n()->__('Commits')));
        }

        public function listen_projectheader(TBGEvent $event)
        {
            TBGActionComponent::includeComponent('vcs_integration/projectheaderbutton');
        }

        public function listen_projectconfig_tab(TBGEvent $event)
        {
            TBGActionComponent::includeComponent('vcs_integration/projectconfig_tab', array('selected_tab' => $event->getParameter('selected_tab')));
        }

        public function listen_projectconfig_panel(TBGEvent $event)
        {
            TBGActionComponent::includeComponent('vcs_integration/projectconfig_panel', array('selected_tab' => $event->getParameter('selected_tab'), 'access_level' => $event->getParameter('access_level'), 'project' => $event->getParameter('project')));
        }

        public function listen_notificationview(TBGEvent $event)
        {
            if ($event->getSubject()->getModuleName() != 'vcs_integration')
                return;

            TBGActionComponent::includeComponent('vcs_integration/notification_view', array('notification' => $event->getSubject()));
            $event->setProcessed();
        }

        public function listen_TBGNotification_getTarget(TBGEvent $event)
        {
            if ($event->getSubject()->getModuleName() != 'vcs_integration')
                return;

            $commit = Commits::getTable()->selectById($event->getSubject()->getTargetID());
            $event->setReturnValue($commit);
            $event->setProcessed();
        }

        public function listen_getcommit(TBGEvent $event)
        {
            if ($event->getSubject() == 'vcs_integration_getcommit')
            {
                $event->setReturnValue('vcs_integration/commitbackdrop');
                $event->addToReturnList(TBGContext::getRequest()->getParameter('commit_id'), 'commit_id');
                $event->setProcessed();
            }
        }

        public function listen_viewissue_panel(TBGEvent $event)
        {
            if (TBGContext::getModule('vcs_integration')->getSetting('vcs_mode_' . TBGContext::getCurrentProject()->getID()) == self::MODE_DISABLED)
                return;

            $links = IssueLink::getCommitsByIssue($event->getSubject());
            TBGActionComponent::includeComponent('vcs_integration/viewissue_commits', array('links' => $links, 'projectId' => $event->getSubject()->getProject()->getID()));
        }

        public static function processCommit(TBGProject $project, $commit_msg, $old_rev, $new_rev, $date = null, $changed, $author, $branch = null)
        {
            $output = '';
            TBGContext::setCurrentProject($project);

            if ($project->isArchived()): return;
            endif;

            try
            {
                TBGContext::getI18n();
            }
            catch (Exception $e)
            {
                TBGContext::reinitializeI18n(null);
            }

            // Is VCS Integration enabled?
            if (TBGSettings::get('vcs_mode_' . $project->getID(), 'vcs_integration') == self::MODE_DISABLED)
            {
                $output .= '[VCS ' . $project->getKey() . '] This project does not use VCS Integration' . "\n";
                return $output;
            }

            // Parse the commit message, and obtain the issues and transitions for issues.
            $parsed_commit = TBGIssue::getIssuesFromTextByRegex($commit_msg);
            $issues = $parsed_commit["issues"];
            $transitions = $parsed_commit["transitions"];

            // Build list of affected files
            $file_lines = preg_split('/[\n\r]+/', $changed);
            $files = array();

            foreach ($file_lines as $aline)
            {
                $action = mb_substr($aline, 0, 1);

                if ($action == "A" || $action == "U" || $action == "D" || $action == "M")
                {
                    $theline = trim(mb_substr($aline, 1));
                    $files[] = array($action, $theline);
                }
            }

            // Find author of commit, fallback is guest
            /*
             * Some VCSes use a different format of storing the committer's name. Systems like bzr, git and hg use the format
             * Joe Bloggs <me@example.com>, instead of a classic username. Therefore a user will be found via 4 queries:
             * a) First we extract the email if there is one, and find a user with that email
             * b) If one is not found - or if no email was specified, then instead test against the real name (using the name part if there was an email)
             * c) the username or full name is checked against the friendly name field
             * d) and if we still havent found one, then we check against the username
             * e) and if we STILL havent found one, we use the guest user
             */

            if (preg_match("/(?<=<)(.*)(?=>)/", $author, $matches))
            {
                $email = $matches[0];

                // a)
                $user = TBGUsersTable::getTable()->getByEmail($email);

                if (!$user instanceof TBGUser)
                {
                    // Not found by email
                    preg_match("/(?<=^)(.*)(?= <)/", $author, $matches);
                    $author = $matches[0];
                }
            }

            // b)
            if (!$user instanceof TBGUser)
                $user = TBGUsersTable::getTable()->getByRealname($author);

            // c)
            if (!$user instanceof TBGUser)
                $user = TBGUsersTable::getTable()->getByBuddyname($author);

            // d)
            if (!$user instanceof TBGUser)
                $user = TBGUsersTable::getTable()->getByUsername($author);

            // e)
            if (!$user instanceof TBGUser)
                $user = TBGSettings::getDefaultUser();

            TBGContext::setUser($user);
            TBGSettings::forceSettingsReload();
            TBGContext::cacheAllPermissions();

            $output .= '[VCS ' . $project->getKey() . '] Commit to be logged by user ' . $user->getName() . "\n";

            if ($date == null):
                $date = NOW;
            endif;

            // Create the commit data
            $commit = new Commit();
            $commit->setAuthor($user);
            $commit->setDate($date);
            $commit->setLog($commit_msg);
            $commit->setPreviousRevision($old_rev);
            $commit->setRevision($new_rev);
            $commit->setProject($project);

            if ($branch !== null)
            {
                $data = 'branch:' . $branch;
                $commit->setMiscData($data);
            }

            $commit->save();

            $output .= '[VCS ' . $project->getKey() . '] Commit logged with revision ' . $commit->getRevision() . "\n";

            // Iterate over affected issues and update them.
            foreach ($issues as $issue)
            {
                $inst = new IssueLink();
                $inst->setIssue($issue);
                $inst->setCommit($commit);
                $inst->save();

                // Process all commit-message transitions for an issue.
                foreach ($transitions[$issue->getFormattedIssueNo()] as $transition)
                {
                    if (TBGSettings::get('vcs_workflow_' . $project->getID(), 'vcs_integration') == self::WORKFLOW_ENABLED)
                    {
                        TBGContext::setUser($user);
                        TBGSettings::forceSettingsReload();
                        TBGContext::cacheAllPermissions();

                        if ($issue->isWorkflowTransitionsAvailable())
                        {
                            // Go through the list of possible transitions for an issue. Only
                            // process transitions that are applicable to issue's workflow.
                            foreach ($issue->getAvailableWorkflowTransitions() as $possible_transition)
                            {
                                if (mb_strtolower($possible_transition->getName()) == mb_strtolower($transition[0]))
                                {
                                    $output .= '[VCS ' . $project->getKey() . '] Running transition ' . $transition[0] . ' on issue ' . $issue->getFormattedIssueNo() . "\n";
                                    // String representation of parameters. Used for log message.
                                    $parameters_string = "";

                                    // Iterate over the list of this transition's parameters, and
                                    // set them.
                                    foreach ($transition[1] as $parameter => $value)
                                    {
                                        $parameters_string .= "$parameter=$value ";

                                        switch ($parameter)
                                        {
                                            case 'resolution':
                                                if (($resolution = TBGResolution::getResolutionByKeyish($value)) instanceof TBGResolution)
                                                {
                                                    TBGContext::getRequest()->setParameter('resolution_id', $resolution->getID());
                                                }
                                                break;
                                            case 'status':
                                                if (($status = TBGStatus::getStatusByKeyish($value)) instanceof TBGStatus)
                                                {
                                                    TBGContext::getRequest()->setParameter('status_id', $status->getID());
                                                }
                                                break;
                                        }
                                    }

                                    // Run the transition.
                                    $possible_transition->transitionIssueToOutgoingStepWithoutRequest($issue);

                                    // Log an informative message about the transition.
                                    $output .= '[VCS ' . $project->getKey() . '] Ran transition ' . $possible_transition->getName() . ' with parameters \'' . $parameters_string . '\' on issue ' . $issue->getFormattedIssueNo() . "\n";
                                }
                            }
                        }
                    }
                }

                $issue->addSystemComment(TBGContext::getI18n()->__('This issue has been updated with the latest changes from the code repository.<source>%commit_msg</source>', array('%commit_msg' => $commit_msg)), $user->getID());
                $output .= '[VCS ' . $project->getKey() . '] Updated issue ' . $issue->getFormattedIssueNo() . "\n";
            }

            // Create file links
            foreach ($files as $afile)
            {
                // index 0 is action, index 1 is file
                $inst = new File();
                $inst->setAction($afile[0]);
                $inst->setFile($afile[1]);
                $inst->setCommit($commit);
                $inst->save();

                $output .= '[VCS ' . $project->getKey() . '] Added with action ' . $afile[0] . ' file ' . $afile[1] . "\n";
            }

            TBGEvent::createNew('vcs_integration', 'new_commit')->trigger(array('commit' => $commit));

            return $output;
        }

    }
