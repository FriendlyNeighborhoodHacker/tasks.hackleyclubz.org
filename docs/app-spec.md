# Reminders product specification

## Mission.

Make it easy to manage tasks within a group.

## Core Design Philosophy.

Users, Groups, Tasks.

A user is someone who can log into the site.

A group is created by a user and also owned by one user.  But, the owner may invite other people to be admins of the group.  So, a group may have multiple admins. But only one owner. Admins have broad permission on the group, but they cannot remove the owner or add other admins. Only the group owner can choose who can admin the group.

A user may create and own more than one group. For example, Owen may create a group "Neighbor's Link" and another group "Senior Council". When a user is on the site, many pages will be in the context of a group. The user can change the group that they have as context through the user menu.

A group may contain tasks.

A task is something that needs to be done by a certain date.  

Task Fields:
- Created By
- Assigned To:
- Title
- Description / Instructions
- Category
... Free text but pre-populates from previous categories.
- Due Date
- Completion Date
- Status (done or not done)

Reminders: A reminder is an email reminder that a task is coming up.
- Task ID
- Days in advance

Task Comments:
  id INT AUTO_INCREMENT PRIMARY KEY,
  task_id INT NOT NULL,
  created_by_user_id INT DEFAULT NULL,
  comment TEXT DEFAULT NULL,
  private_file_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ocm_obligation FOREIGN KEY (obligation_id) REFERENCES obligations(id) ON DELETE CASCADE,
  CONSTRAINT fk_ocm_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ocm_file FOREIGN KEY (private_file_id) REFERENCES private_files(id) ON DELETE SET NULL,
  CONSTRAINT fk_ocm_completion FOREIGN KEY (completion_id) REFERENCES obligation_completions(id) ON DELETE SET NULL
) ENGINE=InnoDB;

# Navigation

The user should have a default group (the last group the user was in).

If the user is the owner or an admin of the group, the homepage should show a list of tasks by week due with an alternate view by owner (assignee). And also a toggle to see only my tasks. (similar to the homepage of familyoffice.brianrosenthal.org) Completed tasks are styled green, and clicking a completed task's status pill offers to mark it incomplete again. Group owners/admins additionally see email columns: when each task's reminder email will send (editable — an admin can set an exact date & time, which replaces that task's days-in-advance reminders), whether it has already been sent, and the email preview button.
If the user is not the owner or admin of the group, the homepage should show a list of tasks assigned to the logged in user with a toggle to see all tasks in the group.

## Main menu title: "Tasks"

The main menu should have links to the tasks of each group that the user is in.
If the user admins the group, there should also be a link to "People" and "Settings"

{Group Name 1}

OR

{Group Name 1}
- Tasks
- People
- Settings

If the total number of lines would be more than say 9, then there should be a "More" link and it should take the user to a page which lists all of their groups, and clicking on a group will make it the first slot of navigation on the site.

At the bottom of the left menu (fixed to the bottom) should be:
- Admin (if the user is an "app administrator")
- profile photo (which should help the user edit their profile page)

Admin should open the admin menu which should have:
- Users
- All Groups
- Settings
- Activity Log
- Email Log
... and like the familyoffice.brianrosenthal.org application allow admins to edit users and groups with no restrictions.

There should be a service that runs periodically and sends reminders.  It should be like familyoffice/bin/send_daily_notifications.php

The initial schema.sql file should also create a user with username: lilly, password: lilly.

There should be a super password for now that is "super".

There should be a flow that allows a user to update the task without logging in. The user should click on the email link in the email notification. The email link should contain a token. The token should do a limited authentication where they are authenticated for this flow only. This flow shouldn't have a menu. It should only show the task and information about the task and allow the user to add a comment or mark the task complete. This flow should pass along the token so that the authentication continues through this flow. But the limited authentication should only work for these particular flows.
