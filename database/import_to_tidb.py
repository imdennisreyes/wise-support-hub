#!/usr/bin/env python3
"""
import_to_tidb.py
-----------------
Imports the Wise Support Hub into TiDB Cloud using two-phase approach:
  Phase 1 – Apply the clean utf8mb4 schema (database/schema.sql)
  Phase 2 – Import only INSERT rows from the InfinityFree MySQL dump

Usage:
    python database/import_to_tidb.py \
        --host gateway01us-west-2.prod.aws.tidbcloud.com \
        --user BkvyYa4bYPxxckt.root \
        --password "YOUR_PASSWORD" \
        --dump "C:/Users/ddreyes/Downloads/if0_42843757_wise_support.sql"
"""

import argparse
import os
import re
import sys
import mysql.connector


# ── SQL statement splitter (handles ; inside strings) ────────────────────────

def split_statements(sql: str) -> list:
    statements, buf = [], []
    in_string, quote_char = False, ''
    i, n = 0, len(sql)
    while i < n:
        ch = sql[i]
        if in_string:
            buf.append(ch)
            if ch == '\\' and i + 1 < n:
                buf.append(sql[i + 1]); i += 2; continue
            elif ch == quote_char:
                in_string = False
        elif ch in ("'", '"'):
            in_string, quote_char = True, ch; buf.append(ch)
        elif ch == '-' and i + 1 < n and sql[i + 1] == '-':
            while i < n and sql[i] != '\n': i += 1
            continue
        elif ch == '/' and i + 1 < n and sql[i + 1] == '*':
            i += 2
            while i < n - 1 and not (sql[i] == '*' and sql[i + 1] == '/'): i += 1
            i += 2; continue
        elif ch == ';':
            stmt = ''.join(buf).strip()
            if stmt: statements.append(stmt)
            buf = []
        else:
            buf.append(ch)
        i += 1
    last = ''.join(buf).strip()
    if last: statements.append(last)
    return statements


def run_statement(cursor, conn, stmt, phase_label):
    """Execute one statement, print warnings for non-fatal errors."""
    try:
        cursor.execute(stmt)
        conn.commit()
        return True
    except mysql.connector.Error as e:
        if e.errno in (1050, 1061, 1062):   # table exists, dup index, dup entry
            return True
        print(f"  ⚠  [{phase_label}] {e.msg[:140]}")
        return False


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--host',     required=True)
    parser.add_argument('--port',     default=4000, type=int)
    parser.add_argument('--user',     required=True)
    parser.add_argument('--password', required=True)
    parser.add_argument('--database', default='wise_support_hub')
    parser.add_argument('--dump',     required=True)
    args = parser.parse_args()

    script_dir  = os.path.dirname(os.path.abspath(__file__))
    schema_path = os.path.join(script_dir, 'schema.sql')

    if not os.path.exists(schema_path):
        print(f"Error: schema not found at {schema_path}")
        sys.exit(1)
    if not os.path.exists(args.dump):
        print(f"Error: dump not found at {args.dump}")
        sys.exit(1)

    # ── Connect ───────────────────────────────────────────────────────────────
    print(f"Connecting to {args.host}:{args.port} ...")
    conn = mysql.connector.connect(
        host=args.host, port=args.port,
        user=args.user, password=args.password,
        ssl_disabled=False, ssl_verify_cert=False,
        connection_timeout=30,
    )
    cursor = conn.cursor()
    print("Connected.")

    # ── Phase 1: Apply clean schema ───────────────────────────────────────────
    print(f"\nPhase 1: Creating schema in database '{args.database}' ...")
    with open(schema_path, 'r', encoding='utf-8') as f:
        raw_schema = f.read()

    # Replace the hardcoded database name in schema.sql with the target name
    schema_sql = re.sub(
        r'(CREATE DATABASE IF NOT EXISTS\s+)`?\w+`?',
        f'CREATE DATABASE IF NOT EXISTS `{args.database}`',
        raw_schema, count=1, flags=re.IGNORECASE
    )
    schema_sql = re.sub(
        r'^USE\s+`?\w+`?',
        f'USE `{args.database}`',
        schema_sql, flags=re.IGNORECASE | re.MULTILINE
    )

    schema_stmts = split_statements(schema_sql)
    schema_ok, schema_err = 0, 0
    for stmt in schema_stmts:
        if stmt.strip():
            if run_statement(cursor, conn, stmt, 'schema'):
                schema_ok += 1
            else:
                schema_err += 1

    print(f"  Schema: {schema_ok} OK, {schema_err} errors")

    # ── Phase 2: Import INSERT data from dump ─────────────────────────────────
    print(f"\nPhase 2: Importing data from dump ...")
    cursor.execute(f"USE `{args.database}`")
    cursor.execute("SET FOREIGN_KEY_CHECKS = 0")   # disable FK checks during import

    with open(args.dump, 'r', encoding='utf-8', errors='replace') as f:
        dump_sql = f.read()

    all_stmts  = split_statements(dump_sql)
    inserts    = [s for s in all_stmts if re.match(r'\s*INSERT\s+INTO', s, re.IGNORECASE)]
    print(f"  Found {len(inserts)} INSERT statements in dump.")

    data_ok, data_err = 0, 0
    counts = {}
    for stmt in inserts:
        # Extract table name for counting
        m = re.match(r"INSERT\s+INTO\s+`?(\w+)`?", stmt, re.IGNORECASE)
        tbl = m.group(1) if m else '?'
        if run_statement(cursor, conn, stmt, 'data'):
            data_ok += 1
            counts[tbl] = counts.get(tbl, 0) + 1
        else:
            data_err += 1

    cursor.execute("SET FOREIGN_KEY_CHECKS = 1")   # re-enable FK checks
    cursor.close()
    conn.close()

    # ── Summary ───────────────────────────────────────────────────────────────
    print(f"\n✓ Import complete — {data_ok} INSERT statements OK, {data_err} errors\n")
    for t, n in sorted(counts.items()):
        print(f"  {t:35s} {n:>4} insert(s)")

    if data_err == 0:
        print("\n✓ All data imported successfully!")

    print("\n── Set these in Render → Environment ───────────────────────────")
    print(f"  DB_HOST     = {args.host}")
    print(f"  DB_PORT     = {args.port}")
    print(f"  DB_DATABASE = {args.database}")
    print(f"  DB_USERNAME = {args.user}")
    print("  DB_PASSWORD = (your TiDB password)")
    print("  DB_DRIVER   = mysql")
    print("  USE_SQLITE  = (delete this variable)")


if __name__ == '__main__':
    main()
