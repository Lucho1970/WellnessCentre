"""Import an owner-confirmed synthetic export into the empty local Docker test DB."""

import argparse
import gzip
import json
import pathlib
import re
import subprocess
import time


CONTAINER = "wellness-local-test-db-1"
MIGRATIONS = (
    "059_browser_sessions.sql",
    "060_browser_session_integration.sql",
    "061_commit_safe_email.sql",
    "062_practitioner_emergency_access.sql",
    "063_clinic_care_notes.sql",
    "064_practitioner_documents.sql",
)
MYSQL = [
    "docker", "exec", "-i", CONTAINER, "sh", "-c",
    'exec mysql -N -s -uroot -p"$MYSQL_ROOT_PASSWORD" wellness_centre',
]


def mysql(data: bytes, *, result: bool = False) -> str:
    process = subprocess.run(MYSQL, input=data, capture_output=True, check=False)
    if process.returncode:
        message = process.stderr.decode("utf-8", "replace")
        match = re.search(r"ERROR\s+(\d+).*?at line\s+(\d+)", message)
        code, line = match.groups() if match else ("unknown", "unknown")
        raise RuntimeError(f"MySQL failed: exit={process.returncode}, code={code}, line={line}")
    return process.stdout.decode("utf-8", "replace").strip() if result else ""


def trigger_delimiters(sql: str) -> bytes:
    lines = []
    opened = 0
    closed = 0
    for line in sql.splitlines():
        if line.startswith("CREATE TRIGGER "):
            lines.append("DELIMITER //")
            opened += 1
        if line == "END;":
            lines.extend(("END//", "DELIMITER ;"))
            closed += 1
        else:
            lines.append(line)
    if opened != closed or opened == 0:
        raise RuntimeError("Unexpected trigger layout in migration 060")
    return ("\n".join(lines) + "\n").encode("utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--backup", required=True, type=pathlib.Path)
    parser.add_argument("--confirm-synthetic", action="store_true", required=True)
    args = parser.parse_args()
    if not args.backup.is_file() or args.backup.suffix != ".gz":
        raise RuntimeError("Expected an existing .sql.gz backup")
    repo = pathlib.Path(__file__).resolve().parents[2]
    if args.backup.resolve().is_relative_to(repo):
        raise RuntimeError("Keep database exports outside the repository")

    inspect = subprocess.run(
        ["docker", "inspect", CONTAINER], capture_output=True, text=True, check=True
    )
    container = json.loads(inspect.stdout)[0]
    labels = container["Config"]["Labels"] or {}
    if (labels.get("com.docker.compose.project") != "wellness-local-test"
            or labels.get("com.docker.compose.service") != "db"
            or not container["State"]["Running"]
            or any(container["NetworkSettings"]["Ports"].values())
            or "/var/lib/mysql" not in (container["HostConfig"]["Tmpfs"] or {})):
        raise RuntimeError("Target is not the isolated, unpublished, temporary local test DB")

    count_query = b"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wellness_centre';"
    last_error = None
    for _ in range(40):
        try:
            tables = int(mysql(count_query, result=True))
            break
        except RuntimeError as error:
            last_error = error
            time.sleep(0.5)
    else:
        raise RuntimeError(f"Local MySQL did not become ready: {last_error}")
    if tables:
        raise RuntimeError(f"Refusing to import into a nonempty test database ({tables} tables)")

    with gzip.open(args.backup, "rb") as source:
        data = source.read(128 * 1024 * 1024 + 1)
    if len(data) > 128 * 1024 * 1024:
        raise RuntimeError("Export exceeds the 128 MiB local rehearsal limit")
    mysql(data)
    print("Synthetic backup import: PASS", flush=True)

    migration_dir = repo / "api" / "database" / "migrations"
    for filename in MIGRATIONS:
        sql = (migration_dir / filename).read_text(encoding="utf-8")
        payload = trigger_delimiters(sql) if filename.startswith("060_") else sql.encode("utf-8")
        mysql(payload)
        print(f"{filename}: PASS", flush=True)

    tables = int(mysql(b"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wellness_centre';", result=True))
    triggers = int(mysql(b"SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='wellness_centre';", result=True))
    print(f"Restored test database: {tables} tables, {triggers} triggers")


if __name__ == "__main__":
    try:
        main()
    except RuntimeError as error:
        raise SystemExit(str(error)) from None
