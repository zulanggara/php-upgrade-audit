# Framework comparison fixture

A tiny, made-up framework in the shape of CodeIgniter 3 (`CI_` classes, `libraries/`, `helpers/`,
`$config[...]`), used by `tests/run.sh` to test `compare_framework.php`. None of it is ever executed.

- `old/`: framework in use today. `official/`: its official release (`old/` carries one local patch).
- `new/`: framework to move to. It removes `CI_Input::_clean_input_keys()`, `libraries/Encrypt.php`,
  `helpers/legacy_helper.php`; adds return types; and makes `where_in()` require two arguments.
- `app/`: application with deliberate conflicts: `MY_Input::post()` and `MY_Email::send()` lack the new return
  types, `Thing` extends a class that exists in neither version, and `Welcome` calls removed API.
