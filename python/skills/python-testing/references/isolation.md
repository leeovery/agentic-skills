# Test Isolation

**A test sees only what it was handed. Nothing reaches the real network, home directory or environment, and the run itself checks that nothing does.**

Contents: inject the outside world · the network · the home directory and the environment · settings in a test · `main` in a test · the strict config · never loosen a guard.

## Inject the outside world

Code that reaches for the machine can't be tested without touching it; code that's handed the machine can be handed a fake one. Each seam is [python-protocols](../../python-protocols/SKILL.md)'s; in a test:

| The code takes | `main` passes | A test passes |
|---|---|---|
| `now: Callable[[], datetime]` | `partial(datetime.now, UTC)` | `lambda: NOW` |
| `sleep: Callable[[float], None]` | `time.sleep` | a fake clock's `sleep`, which records the wait and returns at once |
| values from `Settings` | `read_settings()`, reading the environment | the values themselves |
| a directory | the configured path | `tmp_path` |
| an `httpx2.Client` | one with the base URL, token and timeout | one over `httpx2.MockTransport` |

The guards below don't replace the seams. They catch the test that slipped past one.

## The network

The drop-in runs pytest with pytest-socket's `--disable-socket`, and `--allow-unix-socket` so asyncio's event loop can wake itself ([python-quality](../../python-quality/SKILL.md)). Any socket a test opens, and any name it looks up, fails it.

With the drop-in's `filterwarnings = ["error"]`, the failure arrives as a warning turned error, `UserWarning: A test tried to use socket.socket.`, or `socket.getaddrinfo` for a lookup: pytest-socket warns before it raises. The run proves the guard is on:

```python
# tests/test_isolation.py
def test_no_test_opens_a_socket() -> None:
    with pytest.raises(UserWarning, match="A test tried to use socket.socket"):
        socket.socket()


def test_no_test_looks_up_a_name() -> None:
    with pytest.raises(UserWarning, match="A test tried to use socket.getaddrinfo"):
        socket.getaddrinfo("pay.example", 443)
```

- **On Windows the block is narrower.** asyncio's socket pair is loopback TCP there, so a Windows job adds `--allow-hosts=127.0.0.1`, and pytest-socket then blocks only a connection to another host. Opening a socket and looking up a name pass, so the two tests above fail on that job: skip them on Windows.
- **No `@pytest.mark.enable_socket` and no `--force-enable-socket`.** A test that needs the network is testing the provider, not the code; it gets `MockTransport`.
- **A blocked call can hide in a swallowed exception.** Code that catches `Exception` around a request turns the block into a degraded result, and the test passes. The guard makes the call fail; only the test's asserts notice the result.

## The home directory and the environment

A session fixture in the root `conftest.py` gives the whole run a throwaway home and none of the app's own variables:

```python
# tests/conftest.py
@pytest.fixture(autouse=True, scope="session")
def _outside_world(tmp_path_factory: pytest.TempPathFactory) -> Iterator[None]:
    """Point the home directories at an empty one, and unset every SHOP_ variable."""
    home = tmp_path_factory.mktemp("home")
    with pytest.MonkeyPatch.context() as env:
        env.setenv("HOME", str(home))
        env.setenv("XDG_CONFIG_HOME", str(home / ".config"))
        for name in Settings.model_fields:
            env.delenv(f"{PREFIX}{name.upper()}", raising=False)
        yield
```

- **`pytest.MonkeyPatch.context()`,** as the `monkeypatch` fixture is per test and this is per session. `setenv` and `delenv` change the environment and undo it after; `setattr` stays banned.
- **The variable names come from `Settings` and its `PREFIX`,** so a setting added later is unset too, and so is every one after a change of prefix, and a developer's real `SHOP_DATABASE` can never reach a test.
- **The run checks it:** `assert Path.home().is_relative_to(tmp_path_factory.getbasetemp())`.
- **A subprocess the code starts** inherits this environment, so it gets the throwaway home as well.

## Settings in a test

`BaseSettings` reads the environment for every field a call leaves out: through keyword arguments, and through `Settings.model_validate({...})` alike. A test that builds settings gives every field:

```python
# tests/test_settings.py
# Every field, as the strings an environment holds. A field left out would be
# read from the process's environment, even through model_validate.
STRINGS = {
    "database": "shop.db",
    "gateway_url": "https://pay.example",
    "gateway_token": "token-1",
    "gateway_timeout": "PT2.5S",
    "log_level": "DEBUG",
}


def test_settings_parse_the_strings_an_environment_holds() -> None:
    settings = Settings.model_validate(STRINGS)

    assert settings.database == Path("shop.db")
    assert settings.gateway_url == HttpUrl("https://pay.example")
    assert settings.gateway_timeout == timedelta(seconds=2.5)
```

With the session fixture unsetting the app's variables, a field left out by mistake fails as missing, rather than passing on the developer's machine and failing on CI. How settings are read and passed down is [python-architecture](../../python-architecture/SKILL.md)'s.

## `main` in a test

A test of `main` itself, of the wiring and the exit codes, sets the whole environment for the call, with `None` for each variable to unset:

```python
# tests/test_cli.py
def environment(tmp_path: Path, **values: str | None) -> dict[str, str | None]:
    """Every SHOP_ variable main reads, valid unless overridden. None unsets one."""
    valid = {
        "SHOP_DATABASE": str(tmp_path / "shop.db"),
        "SHOP_GATEWAY_URL": "https://pay.example",
        "SHOP_GATEWAY_TOKEN": "token-1",
    }
    given = {f"SHOP_{name.upper()}": value for name, value in values.items()}
    return NO_SETTINGS | valid | given


def run_main(
    env: Mapping[str, str | None], argv: Sequence[str]
) -> tuple[int, str, str]:
    """Call main with argv in env, returning its exit code, stdout and stderr."""
    with CliRunner().isolation(env=env) as (stdout, stderr, _):
        return main(argv), stdout.getvalue().decode(), stderr.getvalue().decode()
```

`CliRunner().isolation(env=...)` sets and unsets the variables for the call and restores them after, and captures both streams. Commands themselves are tested through `build_app` over fakes, without `main`; only the wiring and the exit codes need it ([python-actions](../../python-actions/SKILL.md)).

## The strict config

`strict = true`, `filterwarnings = ["error"]`, `--disable-socket`, `--import-mode=prepend` and the asyncio settings are in python-quality's drop-in, each with its reason ([python-quality](../../python-quality/SKILL.md)). Copy them from there; this skill doesn't repeat them.

## Never loosen a guard

A test that needs what a guard blocks has found a missing seam. Give the code the parameter, pass a fake from the test, and leave the guard as it was. An `enable_socket` mark, a real `HOME`, or an `ignore:` line added to get one test green lets every later test through too.
