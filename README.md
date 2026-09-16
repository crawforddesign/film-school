# Film School

Lightweight WordPress course platform for Prize Foundation's "How to Make a Film" content and future courses.

## Requirements

- WordPress 6.4+, PHP 8.0+
- **Advanced Custom Fields Pro**
- **Gravity Forms**, Elite or Nonprofit license (for the Quiz Add-On)

An admin notice appears on any wp-admin screen if either dependency isn't active.

## Setup

1. **Install & activate** — upload the `film-school` folder to `wp-content/plugins/`, then activate it from Plugins. This creates the `wp_film_school_quiz_attempts` table, registers the Student role, and grants Administrators the `unlock_all_lessons` capability.
2. **Flush permalinks** — visit **Settings → Permalinks** and click **Save Changes** (no need to change anything). Required once after activation for `/courses/` and lesson URLs to work. Also required after any plugin *file update* that isn't a fresh activation — `register_activation_hook` only fires on the deactivated→activated transition, not on an overwritten file, so a manual permalink save is the reliable way to pick up rewrite changes.
3. **Build a course** — add a **Course** (Film School → Courses). Add **Units** if the course needs sections, or skip straight to **Lessons** for a flat course — set each lesson's Parent Course (and Parent Unit, if used) and Lesson Order.
4. **Add a quiz (optional)** — build a Pass/Fail-graded quiz under **Forms**, then link it to a lesson via that lesson's **Quiz** field.
5. **Add students** — Users → Add New, with role set to **Student**. Film School → Students is a shortcut to the Users list pre-filtered to that role.
6. **Restrict a course (optional)** — create a **Group** (Film School → Groups), add student members, then set that course's **Restricted To Groups** field. Leave it empty to keep a course open to everyone.
7. **Build the front end in Elementor**:
   - Lesson template (Theme Builder → Single, Post Type: Lesson) — drop in `[lesson_quiz]`, `[lesson_complete]`, and `[course_sidebar]`.
   - Course archive (Theme Builder → Archive, Post Type: Course) — a Loop Grid widget on "Current Query" already reflects each student's access, no shortcode needed.
   - A student profile page (a normal Page, e.g. `/my-progress/`) — `[student_progress_summary]`, `[student_quiz_history]`, and `[student_assignments]`.

## Content model

- **Course** — top-level offering (e.g. "How to Make a Film", "Learning to Drive")
- **Unit** — optional grouping of lessons within a course. Skip entirely for a flat, short course. Units are **structure only**: they have no front-end URL of their own (`public => false`), since nothing in the plugin renders a unit's contents on a unit page and a unit — not being a lesson — never passes through the lesson gate, which made `/unit/...` an ungated route that displayed nothing. Units appear where they're useful: the admin, and nested inside all three sidebars. **Note:** this removes the `/units/` archive added in v1.2.0. If a Units archive or single template was built in Elementor Theme Builder, it no longer has a URL to attach to — delete it. Lesson archives are unaffected.
- **Lesson** — always belongs to a course (`parent_course`); belongs to a unit (`parent_unit`) only if the course uses them.

All three are registered as non-hierarchical post types — the relationships live in ACF Post Object fields, not `post_parent`, since a lesson needs to point at either a course or a unit depending on the course's structure.

## Lesson gating

Set a lesson's **Requires Completion Of** field to lock it until the selected prerequisite lesson is marked complete for that user. Leave it blank for the first lesson in a sequence. Users with the `unlock_all_lessons` capability (administrators, by default) bypass this — add the capability to an instructor/teacher role once that's defined.

Gating requires a logged-in user; anonymous visitors are redirected to log in.

## Quizzes

Quizzes are built entirely in **Forms** (Gravity Forms) using the Quiz Add-On, graded Pass/Fail. Link a quiz to a lesson via the lesson's **Quiz** field. On submission, the result is recorded in `wp_film_school_quiz_attempts` and — if passed — marks the lesson complete, which unlocks anything gated behind it. Quizzes are genuinely optional: a lesson without one is completed via `[lesson_complete]` instead (see **Lesson completion**). Letter-graded quizzes aren't read (no `gquiz_is_pass` value) — keep quiz forms set to Pass/Fail grading.

## Lesson completion

A lesson is marked complete for a student in one of two ways, and which one applies depends on whether the lesson has a quiz:

- **Quiz lesson** — passing the linked quiz completes it, automatically. No button; a "Mark Complete" button next to a quiz would let a student skip the assessment.
- **No quiz** — the student clicks **Mark Complete**, from the `[lesson_complete]` shortcode on the lesson template.

Drop `[lesson_complete]` on the lesson template once and it takes care of itself — it renders nothing on lessons that have a quiz, so there's no need for a display condition. It also renders nothing on a public course, where there's no logged-in user to record anything against.

Completion is what drives prerequisite unlocking, the sidebar checkmarks, the progress bars in `[student_progress_summary]`, and the Grade Book — so **a course whose lessons have no quizzes needs this shortcode on the lesson template.** Without it those lessons can never be completed, which means anything gated behind one stays locked and the course can never reach 100%.

The button accepts `label`, `done_label`, and `undo` attributes:

```
[lesson_complete label="I've watched this" done_label="Watched" undo="no"]
```

Undo is on by default — a student who clicks by mistake can clear it themselves rather than emailing an admin. Set `undo="no"` if completion should be one-way.

The POST is nonce-protected and re-validates every condition server-side (completable, not locked, correct user) rather than trusting the rendered button, then redirects back to the lesson so a refresh doesn't re-submit.

## Assignments

Lessons have an optional **Assignment** field group — a WYSIWYG **Instructions** field, an **Attachment** file field for a downloadable worksheet/template, and a **Link** URL field for an external resource. All three are plain ACF fields with no PHP behind them, so wire them into the lesson template with Elementor's native Dynamic Content (Text Editor widget for Instructions, a File/Button widget for Attachment, a Button widget's Link control for Link) rather than a shortcode — same as any other ACF field. Use a Display Condition ("assignment_instructions is not empty") to hide the section entirely on lessons with no assignment, same pattern as the Quiz section.

A separate **Recap** field group holds **What We Accomplished**, another optional WYSIWYG field for summarizing what the lesson covered. Same deal — wire it in with Dynamic Content, no shortcode.

## Student profile page

Build this as a regular Page (not a Theme Builder template) — `[student_progress_summary]`, `[student_quiz_history]`, and `[student_assignments]` all key off the logged-in user, not off whatever post is being viewed, so there's nothing for a template's Display Condition to target.

Any page containing `[student_progress_summary]` is auto-detected and guarded: an anonymous visitor is redirected to whatever page you've set under **Film School → Settings** ("Login Required Page") rather than seeing a login prompt or 404 — build that page with your own messaging (e.g. "You haven't started any courses yet — start learning today" with a link to the course archive). If no page is set there, it falls back to the site homepage.

## Admin list tables

Courses, Units, and Lessons all have their **Date** column hidden by default (still available via Screen Options — this only changes the default, not what's possible) and relational columns showing how each item fits into the hierarchy: Lessons show **Course** and **Unit**, Courses show **Public Course** (Yes/No), Units show **Parent Course** and **Unit Order**. Course, Unit, and Unit Order are all Quick-Editable — no need to open the full edit screen just to reassign or renumber. Course and Unit also support Bulk Edit (select multiple lessons, choose Edit from Bulk Actions) with a "No Change" default so applying it across a batch doesn't silently overwrite a field nobody touched — saved via a dedicated AJAX call (`ajax_bulk_edit_lessons`) rather than `save_post`, since WordPress's native bulk-edit save only calls `wp_update_post()` (which is what fires `save_post`) when one of WordPress's own recognized fields also changes; a bulk edit touching only our custom fields can otherwise be silently skipped entirely. The Course column is sortable — this required an actual SQL join to the courses themselves so it sorts by the course's title, not just the raw ID stored on the lesson, scoped tightly enough that it never affects any other query on the site. Note Course and Unit aren't cross-filtered in Quick/Bulk Edit (picking a Course doesn't narrow the Unit dropdown), matching the main lesson edit screen's own fields, which don't filter either. The submenu labels themselves read "Courses" / "Units" / "Lessons" rather than WordPress's default "All Courses" / etc.

## Shortcodes

| Shortcode | Where to use it | What it does |
|---|---|---|
| `[lesson_quiz]` | Lesson template only | Renders the quiz linked to the current lesson, if any |
| `[lesson_complete]` | Lesson template only | "Mark Complete" button for a lesson with no quiz — the completion event for that lesson. Becomes a "Completed" badge with an Undo once clicked. Renders nothing on a quiz-linked lesson or a public course |
| `[course_sidebar]` | Lesson template only | Collapsible unit/lesson navigator. On a private course: progress checkmarks, current-lesson highlight, locked-lesson indicators. On a public course: plain links only, no login required |
| `[course_page_sidebar]` | Course template only | Same navigator, rooted at the course itself: the course title as the parent row with its units/lessons nested underneath. Every unit starts open (there's no current lesson to single one out) |
| `[film_school_sidebar]` | Any page (Film School landing page) | Whole-library navigator: every course the visitor can access, each collapsible, with its units and lessons nested inside. Works logged out (public courses only) |
| `[next_lesson]` | Lesson template only | "Next Up" card linking to the next lesson in sequence, with an arrow button. Rolls over to the next unit's first lesson if the current one is last in its unit. Empty on the last lesson in a course |
| `[student_progress_summary]` | Any page (student profile) | Per-course completion bar + "Continue" link, scoped to courses the student can access |
| `[student_quiz_history]` | Any page (student profile) | Table of the logged-in user's quiz attempts, with retake links on fails |
| `[student_assignments]` | Any page (student profile) | To-do list of quizzes the student hasn't passed yet (reachable lessons only) |

`[lesson_quiz]` and `[course_sidebar]` check `is_singular('lesson')` and render nothing anywhere else, including Course and Unit templates; `[course_page_sidebar]` is the mirror image — `is_singular('course')` only. `[film_school_sidebar]` isn't tied to a post type at all, so it can go on a normal page; if that page happens to sit inside a course (or you drop it on a lesson/unit/course template), the course you're currently in is the one that starts expanded, otherwise they all start collapsed.

All three sidebars share one renderer, so per-lesson state is identical across them: checkmarks, current-lesson highlight, and lock icons on a private course; plain links on a public one. Public is decided per course, not per sidebar — in `[film_school_sidebar]` a public course drops to plain links even for a logged-in student, exactly as `[course_sidebar]` does on that course's own lessons. Course visibility in `[film_school_sidebar]` uses the same rule as the course archive (`user_can_access_course()`): public courses for anyone, plus unrestricted or group-assigned ones once logged in. The three `student_*` shortcodes aren't tied to a post type — they only require a logged-in user, so they belong on a standalone profile page rather than a template.

## Grade Book

**Film School → Grade Book** — a course-scoped roster of every `student`-role user: lessons completed, quiz pass/fail counts, last activity, with a click-through to a per-student lesson-by-lesson breakdown. A second dropdown filters the roster to a specific Group's members. Quiz numbers come from `wp_film_school_quiz_attempts`; lesson completion is computed from user meta per-student rather than in one query — fine at nonprofit-course-platform scale, worth revisiting if that grows an order of magnitude.

## Roles

The plugin registers a **Student** role (`read` capability only). Students are redirected out of `wp-admin` and don't see the admin bar — this is a course site for them, not a dashboard.

## Dashboard

The main **Film School** page uses native WP meta boxes (draggable/collapsible, same system as the post-edit screens): an "About" box explaining the content model, a student enrollment count, and a top-5 "Overachievers" leaderboard by total lessons completed.

## Groups

**Groups** are a plugin CPT (Film School → Groups), each with a **Members** field listing Student users — a student can belong to more than one group. Courses get a matching **Restricted To Groups** field: leave it empty and the course is open to every student (the default); add one or more groups and only their members can access it.

Group restriction is enforced at the same gate lesson prerequisites go through (`is_lesson_locked()`), so a locked-by-group lesson behaves identically to a locked-by-prerequisite one everywhere — the sidebar lock icon, the redirect on direct access, and `[student_progress_summary]`.

## Public courses

A course's **Public Course** field opens it up with no login required — overrides Restricted To Groups entirely. Because there's no logged-in user to track state for, this also switches off prerequisite gating and progress tracking for that course's lessons: `[course_sidebar]` still renders for anonymous visitors, but drops to plain links with no checkmarks or locks (nothing to check off), while `[lesson_quiz]` still renders and the quiz can still be taken anonymously — the score just isn't saved anywhere. Public is an all-or-nothing switch per course, not "public but still tracked."

## Course archive

`course` has `has_archive => true`, so `/courses/` exists automatically. Its main query is filtered (`pre_get_posts`) down to whatever the current visitor can access — public courses for anyone, plus (once logged in) unrestricted or group-assigned ones. Anonymous visitors no longer get redirected away from the archive itself; they just see whatever's public.

A **Lesson Count** Dynamic Tag (group: Film School) is available on any widget's Dynamic Content — inside a Loop Item on the course archive, it outputs how many lessons the current Course has. It's a real Elementor Dynamic Tag, not a shortcode, since it's a single computed value rather than markup — use it directly in a Text/Heading widget the same way you'd use an ACF field's dynamic tag.

A second tag, **First Lesson URL** (same group), resolves the current Course's first lesson — its first unit's first lesson if the course uses units, otherwise the first lesson directly under the course. Use it in a Button widget's Link field for a "Start Course" button on a Loop Item.

A third, **Logout URL** (same group), outputs `wp_logout_url()` — use it in a Button widget's Link field for a "Log Out" button, e.g. on the student profile page.

## Automatic updates

Film School isn't listed on wordpress.org, so it ships with [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (vendored at `includes/plugin-update-checker/`), pointed at this repo's GitHub Releases. This is what makes "Update available" and the native "Update Now" button show up on a client site's Plugins page — no manual re-upload/replace needed.

**Cutting a release:**

1. Bump the `Version` header in `film-school.php` (and `FILM_SCHOOL_VERSION`) to match the new tag.
2. On GitHub, draft a new Release from `main` and tag it (e.g. `v1.0.4`).
3. Publish it with a title and changelog. No zip needs to be built or attached — when a release has no matching zip asset, the update checker falls back to GitHub's own auto-generated source zip for the tag, which installs correctly since the repo root already matches the plugin's file layout. Attaching a named zip asset (e.g. `film-school.zip`) still works if one is ever needed — a matching asset takes priority over the fallback.

Installed sites will see the update within ~12 hours (WordPress's normal update-check cadence), or immediately if an admin clicks "Check again" on the Updates screen. From there it's a normal one-click "Update Now". Auto-updates are not enabled by default — an admin can turn on "Enable auto-updates" for Film School from that site's Plugins page to apply releases unattended.

## Uninstalling

Deactivating leaves everything alone. **Deleting** the plugin (Plugins → Delete) runs `uninstall.php`, which removes what the plugin itself created: the `wp_film_school_quiz_attempts` table, per-user lesson progress (`_completed_lessons` user meta), the `unlock_all_lessons` capability, the plugin's options, and the Student role — any user still holding that role is moved to Subscriber first, so nobody is left roleless and locked out of their own account.

**Courses, Units, Lessons, and Groups are deliberately left in place.** That's the client's content, and a plugin delete is too easy to trigger by accident for it to take the curriculum with it. Re-installing brings all of it back intact. Quiz attempt history is the exception — it lives in a plugin-owned table whose rows mean nothing without the plugin, so it goes.

## Not yet built

- Self-service enrollment / open course catalog — intentionally deferred
- Instructor/teacher role and SSO bridge to the existing portal
- Migration of Matrix LMS content (client is copy-pasting manually — no import tooling needed)
