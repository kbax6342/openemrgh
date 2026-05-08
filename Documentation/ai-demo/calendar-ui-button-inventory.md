# OpenEMR Calendar UI Button Inventory

This checklist was created before and during the calendar shell refresh so the new visual layer preserves existing OpenEMR behavior instead of replacing it with static mockup logic.

## Main Shell Inventory

| Existing button/action | Existing selector/id | Existing handler/route | New UI location | Status |
| --- | --- | --- | --- | --- |
| Main navbar / menu rendering | `#mainMenu` | Knockout `menu-template` in `interface/main/tabs/main.php` | Unchanged top navbar | Preserved |
| Main menu toggle | `.navbar-toggler` | Bootstrap collapse target `#mainMenu` | Unchanged top navbar | Preserved |
| Global demographics search input | `#anySearchBox` | Existing search form / enter-key handler | Unchanged top navbar | Preserved |
| Search patient button | `#search_globals` | Knockout `viewPtFinder(...)` binding | Unchanged top navbar | Preserved |
| User dropdown / user data area | `#userData` | `user-data-template` | Unchanged top navbar | Preserved |
| Portal / reminder / service counters | user data template bindings | `goRepeaterServices()` polling | Unchanged top navbar | Preserved |
| Tabs controls | `#tabs_div` | `tabs-controls` template | Unchanged shell | Preserved |
| Tabs frames | `#framesDisplay` | `tabs-frames` template | Unchanged shell | Preserved |
| AI copilot widget CSS load | `copilot_widget.css` include | `main.php` asset load | Unchanged shell | Preserved |
| AI copilot floating widget JS | `copilot_widget.js` include | `main.php` asset load | Unchanged shell | Preserved |
| Background service polling | none | `goRepeaterServices()` + background service fetch | Unchanged shell | Preserved |
| Session timeout / restore behavior | `restoreSession()` | existing session script + handlers | Unchanged shell | Preserved |
| Logout iframe | `#logoutinnerframe` | existing logout behavior | Unchanged shell | Preserved |
| Product registration modal | rendered Twig modal | `product_reg.js.twig` | Unchanged shell | Preserved |
| Version footer | `#versionFooter` | `main.php` footer render | Unchanged shell | Preserved |

## Calendar / Day-Week-Month Inventory

| Existing button/action | Existing selector/id | Existing handler/route | New UI location | Status |
| --- | --- | --- | --- | --- |
| Sidebar toggle | `#menu-toggle` | jQuery toggles `#wrapper.toggled` | Left edge toolbar button | Preserved |
| New Appointment | top toolbar primary action | `newEvt(...)` -> `add_edit_event.php` | Top toolbar action cluster | Preserved |
| Search Appointment | top toolbar primary action | `location="index.php?module=PostCalendar&func=search"` | Top toolbar action cluster | Preserved |
| Today | top toolbar text button | `GoToToday(theform)` | Top toolbar action cluster | Preserved |
| Previous day/week/month | `#prevday`, `#prevweek`, `#prevmonth` | existing navigation URLs | Center date navigation pill | Preserved |
| Next day/week/month | `#nextday`, `#nextweek`, `#nextmonth` | existing navigation URLs | Center date navigation pill | Preserved |
| Printable version | `#printview` | `PrintView(this)` | Right toolbar action cluster | Preserved |
| Refresh | refresh icon button | `refreshme()` | Right toolbar action cluster | Preserved |
| Day View | `#dayview` | `ChangeView(this)` | Right toolbar action cluster | Preserved |
| Week View | `#weekview` | `ChangeView(this)` | Right toolbar action cluster | Preserved |
| Month View | `#monthview` | `ChangeView(this)` | Right toolbar action cluster | Preserved |
| Mini calendar date picker | `#datePicker` | `.tdDatePicker` -> `ChangeDate(this)` | Slide-out left filter panel | Preserved |
| Previous month mini nav | `.tdDatePicker.tdNav` | existing date-picker click route | Slide-out left filter panel | Preserved |
| Next month mini nav | `.tdDatePicker.tdNav` | existing date-picker click route | Slide-out left filter panel | Preserved |
| Facility selector | `#pc_facility` | `ChangeProviders(this)` | Slide-out left filter panel | Preserved |
| Provider selector multi-select | `#pc_username` | `ChangeProviders(this)` | Slide-out left filter panel | Preserved |
| All Users provider option | `option[value="__PC_ALL__"]` | existing provider filtering | Slide-out left filter panel | Preserved |
| Provider close/remove button | `.providerXbtn.userClose` | `providerXclick()` | Provider headers / time rail | Preserved |
| Appointment grid time slots | `#times` and `.timeslot` | existing render loop | Main schedule card | Preserved |
| Clickable time slots | timeslot anchor links | `newEvt(...)` | Main schedule card | Preserved |
| Existing appointment click/open | `.event`, `.month_event` | `EditEvent(...)` -> `oldEvt(...)` | Main schedule card | Preserved |
| Patient navigation from appointment | patient appointment links | `goPid(pid)` | Main schedule card | Preserved |
| Group appointment navigation | therapy group links | `goGid(gid)` / `oldGroupEvt(...)` | Main schedule card | Preserved |
| Translated labels | `xlt/xla/xl` output | existing server-side translation helpers | All retained controls | Preserved |
| CSRF/session restore | `#theform`, `restoreSession()` | existing submit/nav hooks | All retained controls | Preserved |

## Mockup Comparison Notes

- The static mockup was treated as a visual reference only.
- No hardcoded patient names, provider names, admin names, dates, or facilities were introduced.
- Existing dynamic OpenEMR values still drive dates, providers, facilities, appointments, user identity, and navigation.
- The redesign was implemented as a scoped calendar theme plus safe wrapper classes so current routes, handlers, session behavior, and AI copilot behavior remain intact.
