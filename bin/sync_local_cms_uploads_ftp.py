import ftplib
import os
import sys
from pathlib import Path


def ensure_remote_dir(ftp: ftplib.FTP, remote_dir: str) -> None:
    parts = [p for p in remote_dir.strip("/").split("/") if p]
    current = ""
    for part in parts:
        current += "/" + part
        try:
            ftp.mkd(current)
        except Exception:
            pass


def upload_tree(ftp: ftplib.FTP, local_root: Path, remote_root: str) -> int:
    count = 0
    for file_path in local_root.rglob("*"):
        if not file_path.is_file():
            continue
        rel = file_path.relative_to(local_root).as_posix()
        remote_path = f"{remote_root.rstrip('/')}/{rel}"
        remote_dir = remote_path.rsplit("/", 1)[0]
        ensure_remote_dir(ftp, remote_dir)
        with file_path.open("rb") as f:
            ftp.storbinary(f"STOR {remote_path}", f)
        count += 1
    return count


def main() -> int:
    if len(sys.argv) < 5:
        print("Usage: python backend/bin/sync_local_cms_uploads_ftp.py <ftp_host> <ftp_user> <ftp_pass> <local_dir>", file=sys.stderr)
        return 1

    ftp_host = sys.argv[1]
    ftp_user = sys.argv[2]
    ftp_pass = sys.argv[3]
    local_dir = Path(sys.argv[4])
    if not local_dir.exists():
        print(f"Local dir not found: {local_dir}", file=sys.stderr)
        return 1

    candidate_roots = ["/www/uploads/cms", "/public_html/uploads/cms", "/uploads/cms"]

    ftp = ftplib.FTP()
    ftp.connect(ftp_host, 21, timeout=30)
    ftp.login(ftp_user, ftp_pass)
    ftp.set_pasv(True)

    uploaded = 0
    selected_root = None
    last_error = None
    for root in candidate_roots:
        try:
            ensure_remote_dir(ftp, root)
            uploaded = upload_tree(ftp, local_dir, root)
            selected_root = root
            break
        except Exception as e:
            last_error = e
            continue

    ftp.quit()

    if selected_root is None:
        print(f"Upload failed for all candidate roots. Last error: {last_error}", file=sys.stderr)
        return 1

    print(f"Uploaded {uploaded} files to FTP root {selected_root}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

