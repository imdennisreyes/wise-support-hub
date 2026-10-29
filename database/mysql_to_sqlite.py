#!/usr/bin/env python3
"""
mysql_to_sqlite.py
------------------
Converts a phpMyAdmin MySQL dump to a SQLite database for the Wise Support Hub.

Usage (from the project root):
    python database/mysql_to_sqlite.py "C:/Users/ddreyes/Downloads/if0_42843757_wise_support.sql"

Output:
    database/support_hub.sqlite
"""

import sqlite3
import re
import os
import sys


TABLES_TO_IMPORT = {
    'roles', 'users', 'systems', 'modules', 'categories', 'carriers',
    'tags', 'knowledge_articles', 'article_tags', 'related_articles',
    'api_tokens', 'article_feedback',
}


# ---------------------------------------------------------------------------
# SQL statement splitter — handles ';' inside string literals correctly
# ---------------------------------------------------------------------------

def split_statements(sql: str) -> list[str]:
    """Split a SQL dump into individual statements, ignoring ; inside strings."""
    statements = []
    buf = []
    in_string = False
    quote_char = ''
    i = 0
    n = len(sql)

    while i < n:
        ch = sql[i]

        if in_string:
            buf.append(ch)
            if ch == '\\' and i + 1 < n:        # MySQL escape sequence
                buf.append(sql[i + 1])
                i += 2
                continue
            elif ch == quote_char:
                in_string = False
        elif ch in ("'", '"'):
            in_string = True
            quote_char = ch
            buf.append(ch)
        elif ch == '-' and i + 1 < n and sql[i + 1] == '-':  # -- comment
            while i < n and sql[i] != '\n':
                i += 1
            continue
        elif ch == '/' and i + 1 < n and sql[i + 1] == '*':  # /* comment */
            i += 2
            while i < n - 1 and not (sql[i] == '*' and sql[i + 1] == '/'):
                i += 1
            i += 2
            continue
        elif ch == ';':
            stmt = ''.join(buf).strip()
            if stmt:
                statements.append(stmt)
            buf = []
        else:
            buf.append(ch)

        i += 1

    last = ''.join(buf).strip()
    if last:
        statements.append(last)

    return statements


# ---------------------------------------------------------------------------
# MySQL value parser — handles strings, NULL, numbers
# ---------------------------------------------------------------------------

def parse_value(s: str, pos: int):
    """Parse one MySQL value at pos. Returns (python_value, new_pos)."""
    while pos < len(s) and s[pos] in ' \t':
        pos += 1

    if pos >= len(s):
        return None, pos

    if s[pos:pos + 4].upper() == 'NULL':
        return None, pos + 4

    if s[pos] == "'":
        pos += 1
        chars = []
        ESC = {'n': '\n', 'r': '\r', 't': '\t', '\\': '\\',
               "'": "'", '"': '"', '0': '\x00', 'Z': '\x1a', 'b': '\x08'}
        while pos < len(s):
            ch = s[pos]
            if ch == '\\' and pos + 1 < len(s):
                chars.append(ESC.get(s[pos + 1], s[pos + 1]))
                pos += 2
            elif ch == "'":
                pos += 1
                if pos < len(s) and s[pos] == "'":   # '' escape
                    chars.append("'")
                    pos += 1
                else:
                    break
            else:
                chars.append(ch)
                pos += 1
        return ''.join(chars), pos

    end = pos
    while end < len(s) and s[end] not in ',)':
        end += 1
    raw = s[pos:end].strip()
    try:
        return (float(raw) if '.' in raw else int(raw)), end
    except ValueError:
        return (raw if raw else None), end


def parse_row(s: str, pos: int):
    """Parse one (v1, v2, ...) row. Returns (tuple, new_pos)."""
    while pos < len(s) and s[pos] != '(':
        pos += 1
    if pos >= len(s):
        return None, pos
    pos += 1  # skip '('

    values = []
    while pos < len(s) and s[pos] != ')':
        val, pos = parse_value(s, pos)
        values.append(val)
        while pos < len(s) and s[pos] in ' \t':
            pos += 1
        if pos < len(s) and s[pos] == ',':
            pos += 1

    if pos < len(s) and s[pos] == ')':
        pos += 1
    return tuple(values), pos


def parse_insert(stmt: str):
    """
    Parse one INSERT statement.
    Returns (table_name, columns_or_None, list_of_row_tuples).
    """
    # With explicit column list
    m = re.match(
        r"INSERT\s+INTO\s+`?(\w+)`?\s*\(([^)]*)\)\s*VALUES\s*(.*)",
        stmt, re.DOTALL | re.IGNORECASE
    )
    if m:
        table  = m.group(1)
        cols   = [c.strip().strip('`') for c in m.group(2).split(',')]
        vals_s = m.group(3)
    else:
        m = re.match(
            r"INSERT\s+INTO\s+`?(\w+)`?\s*VALUES\s*(.*)",
            stmt, re.DOTALL | re.IGNORECASE
        )
        if not m:
            return None, None, []
        table  = m.group(1)
        cols   = None
        vals_s = m.group(2)

    rows, pos = [], 0
    while pos < len(vals_s):
        while pos < len(vals_s) and vals_s[pos] in ' \t\n\r,':
            pos += 1
        if pos >= len(vals_s) or vals_s[pos] == ';':
            break
        if vals_s[pos] == '(':
            row, pos = parse_row(vals_s, pos)
            if row is not None:
                rows.append(row)
        else:
            pos += 1

    return table, cols, rows


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    script_dir  = os.path.dirname(os.path.abspath(__file__))
    schema_path = os.path.join(script_dir, 'sqlite_schema.sql')
    output_path = os.path.join(script_dir, 'support_hub.sqlite')

    if len(sys.argv) < 2:
        print("Usage: python database/mysql_to_sqlite.py <path_to_dump.sql>")
        sys.exit(1)

    dump_path = sys.argv[1]

    for path, label in [(dump_path, 'MySQL dump'), (schema_path, 'SQLite schema')]:
        if not os.path.exists(path):
            print(f"Error: {label} not found: {path}")
            sys.exit(1)

    print(f"Reading: {dump_path}")
    with open(dump_path, 'r', encoding='utf-8', errors='replace') as f:
        dump = f.read()

    print("Splitting SQL statements (this may take a moment for large dumps)...")
    statements = split_statements(dump)
    inserts = [s for s in statements if re.match(r'\s*INSERT\s+INTO', s, re.IGNORECASE)]
    print(f"Found {len(inserts)} INSERT statements.")

    if os.path.exists(output_path):
        os.remove(output_path)

    conn = sqlite3.connect(output_path)
    conn.execute("PRAGMA foreign_keys = OFF")
    conn.execute("PRAGMA journal_mode = WAL")

    with open(schema_path, 'r', encoding='utf-8') as f:
        conn.executescript(f.read())
    conn.commit()
    print("Schema applied.")

    counts  = {}
    skipped = {}

    for stmt in inserts:
        table, cols, rows = parse_insert(stmt)

        if not table or table not in TABLES_TO_IMPORT or not rows:
            continue

        if cols:
            col_sql = '(' + ', '.join(cols) + ')'
            ph      = '(' + ', '.join(['?'] * len(cols)) + ')'
            sql     = f"INSERT OR REPLACE INTO {table} {col_sql} VALUES {ph}"
            # Make sure each row has the right number of values
            valid = [r for r in rows if len(r) == len(cols)]
            bad   = len(rows) - len(valid)
        else:
            if not rows:
                continue
            ph    = '(' + ', '.join(['?'] * len(rows[0])) + ')'
            sql   = f"INSERT OR REPLACE INTO {table} VALUES {ph}"
            valid = rows
            bad   = 0

        if bad:
            skipped[table] = skipped.get(table, 0) + bad

        if valid:
            try:
                conn.executemany(sql, valid)
                counts[table] = counts.get(table, 0) + len(valid)
            except sqlite3.Error as e:
                skipped[table] = skipped.get(table, 0) + len(valid)
                print(f"  ⚠  {table}: {e}")

    conn.execute("PRAGMA foreign_keys = ON")
    conn.commit()
    conn.close()

    total = sum(counts.values())
    print(f"\n✓ Import complete — {total} rows across {len(counts)} tables\n")
    for t, n in sorted(counts.items()):
        print(f"  {t:35s} {n:>6} rows")
    if skipped:
        print("\nSkipped (column mismatch or errors):")
        for t, n in skipped.items():
            print(f"  {t}: {n} rows")

    print(f"\nOutput: {output_path}")
    print("\n── Next steps ──────────────────────────────────────────────────")
    print("1. Run:  git add database/support_hub.sqlite")
    print("2. Run:  git commit -m \"Add pre-populated SQLite snapshot\"")
    print("3. Run:  git push origin main")
    print("4. In Render → Environment, add:  USE_SQLITE = true")
    print("5. Redeploy on Render")


if __name__ == '__main__':
    main()
