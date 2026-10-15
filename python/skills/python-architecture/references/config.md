# Config

**One frozen `BaseSettings` holds everything the program reads from its environment, built once in `main`'s wiring, checked whole, and handed out as values.**

Contents: the one `Settings` · field types · defaults and secrets · built in `main`, nowhere else · every bad value at once · `.env` files · settings in tests.

## The one `Settings`

```python
# src/shop/settings.py
"""The shop's configuration, read once from SHOP_ environment variables."""

from datetime import timedelta
from pathlib import Path
from typing import Literal

from pydantic import HttpUrl, SecretStr, ValidationError
from pydantic_settings import BaseSettings, SettingsConfigDict

PREFIX = "SHOP_"


class Settings(BaseSettings):
    """Everything the shop reads from its environment, checked when it's built.

    Every missing or bad value is reported at once, without the values given,
    and the secret has no default, so a deploy that forgets it fails at start.
    """

    model_config = SettingsConfigDict(
        env_prefix=PREFIX, frozen=True, hide_input_in_errors=True
    )

    database: Path
    gateway_url: HttpUrl
    gateway_token: SecretStr
    gateway_timeout: timedelta = timedelta(seconds=10)
    log_level: Literal["DEBUG", "INFO", "WARNING", "ERROR"] = "INFO"


class SettingsError(Exception):
    """The environment's settings are missing or bad: the message names each one."""


def read_settings() -> Settings:
    """The settings, read from the environment.

    Raises SettingsError naming each variable that's missing or bad, never its
    value, so run prints it as the user's to fix.
    """
    try:
        return Settings()
    except ValidationError as err:
        problems = "".join(
            f"\n  {PREFIX}{'_'.join(map(str, error['loc'])).upper()}: {error['msg']}"
            for error in err.errors(include_url=False, include_input=False)
        )
        raise SettingsError(f"settings missing or bad:{problems}") from err
```

- **One class for the program,** one prefix (`SHOP_`), and a field per setting, named for its concern first: `gateway_url`, `gateway_token`, `gateway_timeout`. The variable is the prefix and the field: `SHOP_GATEWAY_URL`.
- **Flat, not nested models per concern.** `main` hands the gateway its URL, token and timeout as separate values anyway, and `SHOP_GATEWAY_URL` reads plainer in a deploy file than `SHOP_GATEWAY__URL`.
- **Frozen,** so nothing changes a setting after start.
- **`hide_input_in_errors=True`,** so a value given in the wrong field, a token pasted as the URL, never reaches the error and the log.

## Field types

The type does the parsing, so a bad value fails at start with the field's name, never mid-run as a string that didn't parse:

| Setting | Type | What it accepts |
|---|---|---|
| a file or directory | `Path` | any path; check it exists where it's opened |
| a URL | `HttpUrl` | `http` or `https` with a host; `str()` of it adds a trailing `/` to a bare host |
| a secret | `SecretStr` | any string, shown as `**********` |
| a duration | `timedelta` | ISO 8601 (`PT10S`, `PT2.5S`) or `HH:MM:SS`; a bare `10` is refused, so nobody guesses the unit |
| one of a fixed set | `Literal[...]`, or a `StrEnum` the domain already has | only those values, each listed in the error |
| a count or a size | `int`, with `Field(gt=0)` where zero means nothing | digits |

Never a `str` that the code parses later, and never an `int` of seconds.

## Defaults and secrets

- **A default is a value that's right wherever the program runs:** a timeout, a log level.
- **No default for a secret** (`gateway_token`), **or for anything that says where data goes** (`database`, `gateway_url`). A deploy that forgets one fails at start with the field's name; a default would point production at a local file, or at nothing.
- **A secret is `SecretStr` from the environment to the one call that needs it.** `get_secret_value()` appears once, in the wiring, where the token becomes the client's header. Past that point it's the adapter's; a secret held in the domain is [python-types](../../python-types/SKILL.md)' redacting type.
- **No `is_production` flag.** A setting says what differs (a URL, a timeout), never which environment this is, or the code fills with branches on it.

## Built in `main`, nowhere else

- **`read_settings()` is called in `from_environment`,** the wiring `main` hands the CLI, once a command runs ([SKILL.md](../SKILL.md#pattern)). It's the one place the environment is read; ruff's ban list rejects `os.environ` and `os.getenv` in every other file.
- **`main` passes values, never the settings object:** `database=settings.database`, and a client factory with the URL, the token's header and the timeout bound in. `_authorised` takes the token alone. A component that takes `Settings` depends on every setting and on pydantic, and its test must build all of them.
- **import-linter holds it.** `shop.settings` shares a layer with the other edges, `shop.api` and `shop.consumers`, which may not import each other, and sits above the adapters and the domain, which may not import it. Only `shop.cli` can.
- **Not at import.** `settings = Settings()` at module level reads the environment the moment anything imports the module, tests included; python-quality's guard on import-time calls reports it.

## Every bad value at once

pydantic validates every field before raising, so one `ValidationError` names each missing and each bad value. `read_settings` turns it into a `SettingsError` naming each variable, never its value, and `run` prints that on stderr with exit code 1 ([python-actions](../../python-actions/SKILL.md)):

```
shop: settings missing or bad:
  SHOP_DATABASE: Field required
  SHOP_GATEWAY_URL: Field required
  SHOP_GATEWAY_TOKEN: Field required
```

The settings' failure gets its own exception so `run` can tell it from a `ValidationError` anywhere else, which is a bug and is logged with its traceback.

A rule across fields (a timeout no longer than a limit set beside it) is a `@model_validator(mode="after")` on `Settings`, so it fails at start too.

## `.env` files

- **`Settings` sets no `env_file`.** pydantic-settings reads a configured `env_file` on every construction, `model_validate` included, so a developer's `.env` would reach the tests on that machine and nowhere else.
- **In development, uv loads it:** `uv run --env-file .env shop cancel o-1`, or `UV_ENV_FILE=.env` in the shell. The program sees ordinary environment variables.
- **`.env` goes in `.gitignore`;** `uv init` doesn't add it. A committed `.env.example` lists the variables, with no secrets.
- **No `load_dotenv()`** anywhere: it changes the process's environment, at import if it's called there.

## Settings in tests

`BaseSettings` reads the process's environment for every field it isn't given, however it's built: keyword arguments win over the environment, but a field left out is read from it, and `model_validate` reads it too. So:

- **A test that builds `Settings` gives every field:**

```python
# tests/test_settings.py
"""The settings, given every field, so nothing comes from the machine's environment."""

from datetime import timedelta
from pathlib import Path

import pytest
from pydantic import HttpUrl, SecretStr, ValidationError

from shop.settings import Settings

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


def test_every_bad_setting_is_reported_at_once_without_its_value() -> None:
    strings = STRINGS | {"gateway_url": "not a url", "gateway_timeout": "10"}

    with pytest.raises(ValidationError) as caught:
        Settings.model_validate(strings)

    assert [error["loc"] for error in caught.value.errors()] == [
        ("gateway_url",),
        ("gateway_timeout",),
    ]
    assert "not a url" not in str(caught.value)


def test_the_token_never_shows_in_the_settings_repr() -> None:
    settings = Settings(
        database=Path("shop.db"),
        gateway_url=HttpUrl("https://pay.example"),
        gateway_token=SecretStr("token-1"),
        gateway_timeout=timedelta(seconds=10),
        log_level="INFO",
    )

    assert "token-1" not in repr(settings)
```

- **A test of `main` scopes the environment** with `CliRunner().isolation(env=...)`, which sets each variable for the block and unsets each one given as `None`. `NO_SETTINGS` names every `SHOP_` variable `Settings` reads, from its fields, so a variable on the machine running the tests can't fill a gap:

```python
# tests/test_cli.py
...

NO_SETTINGS = dict.fromkeys(
    (f"SHOP_{name.upper()}" for name in Settings.model_fields), None
)

...


def environment(tmp_path: Path, **values: str | None) -> dict[str, str | None]:
    """Every SHOP_ variable main reads, valid unless overridden. None unsets one."""
    valid = {
        "SHOP_DATABASE": str(tmp_path / "shop.db"),
        "SHOP_GATEWAY_URL": "https://pay.example",
        "SHOP_GATEWAY_TOKEN": "token-1",
    }
    given = {f"SHOP_{name.upper()}": value for name, value in values.items()}
    return NO_SETTINGS | valid | given


...


def test_main_names_every_missing_setting_and_exits_1(tmp_path: Path) -> None:
    env = environment(tmp_path, gateway_url=None, gateway_token=None)

    code, _, stderr = run_main(env, ["cancel", "o-1"])

    assert code == 1
    assert stderr == (
        "shop: settings missing or bad:\n"
        "  SHOP_GATEWAY_URL: Field required\n"
        "  SHOP_GATEWAY_TOKEN: Field required\n"
    )
```

- **No `monkeypatch.setenv` around a component.** A component never reads the environment, so its test passes the value as a parameter; only `main`'s tests set variables.
