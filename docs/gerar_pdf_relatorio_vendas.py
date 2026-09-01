from pathlib import Path


PAGE_WIDTH = 595
PAGE_HEIGHT = 842
LEFT = 50
TOP = 780
LINE_HEIGHT = 15
FONT_SIZE = 10
LINES_PER_PAGE = 46


def pdf_escape(text: str) -> str:
    return text.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def build_pages(lines: list[str]) -> list[list[str]]:
    pages = []
    current = []
    for line in lines:
        current.append(line.rstrip("\n"))
        if len(current) >= LINES_PER_PAGE:
            pages.append(current)
            current = []
    if current:
        pages.append(current)
    return pages


def content_stream(page_lines: list[str]) -> bytes:
    chunks = ["BT", f"/F1 {FONT_SIZE} Tf"]
    y = TOP
    for line in page_lines:
        safe = pdf_escape(line)
        chunks.append(f"1 0 0 1 {LEFT} {y} Tm ({safe}) Tj")
        y -= LINE_HEIGHT
    chunks.append("ET")
    return "\n".join(chunks).encode("latin-1", errors="replace")


def write_pdf(text_path: Path, pdf_path: Path) -> None:
    lines = text_path.read_text(encoding="utf-8").splitlines()
    pages = build_pages(lines)

    objects: list[bytes] = []

    def add_object(data: bytes) -> int:
        objects.append(data)
        return len(objects)

    font_id = add_object(b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>")

    page_ids = []
    content_ids = []

    for page_lines in pages:
        stream = content_stream(page_lines)
        content_id = add_object(
            f"<< /Length {len(stream)} >>\nstream\n".encode("latin-1")
            + stream
            + b"\nendstream"
        )
        content_ids.append(content_id)
        page_ids.append(0)

    pages_root_id = add_object(b"")

    for i, content_id in enumerate(content_ids):
        page_obj = (
            f"<< /Type /Page /Parent {pages_root_id} 0 R /MediaBox [0 0 {PAGE_WIDTH} {PAGE_HEIGHT}] "
            f"/Resources << /Font << /F1 {font_id} 0 R >> >> /Contents {content_id} 0 R >>"
        ).encode("latin-1")
        page_ids[i] = add_object(page_obj)

    kids = " ".join(f"{pid} 0 R" for pid in page_ids)
    objects[pages_root_id - 1] = f"<< /Type /Pages /Count {len(page_ids)} /Kids [{kids}] >>".encode("latin-1")
    catalog_id = add_object(f"<< /Type /Catalog /Pages {pages_root_id} 0 R >>".encode("latin-1"))

    output = bytearray(b"%PDF-1.4\n")
    xref = [0]

    for index, obj in enumerate(objects, start=1):
        xref.append(len(output))
        output.extend(f"{index} 0 obj\n".encode("latin-1"))
        output.extend(obj)
        output.extend(b"\nendobj\n")

    xref_start = len(output)
    output.extend(f"xref\n0 {len(objects) + 1}\n".encode("latin-1"))
    output.extend(b"0000000000 65535 f \n")
    for offset in xref[1:]:
        output.extend(f"{offset:010d} 00000 n \n".encode("latin-1"))

    trailer = (
        f"trailer\n<< /Size {len(objects) + 1} /Root {catalog_id} 0 R >>\n"
        f"startxref\n{xref_start}\n%%EOF\n"
    )
    output.extend(trailer.encode("latin-1"))
    pdf_path.write_bytes(output)


if __name__ == "__main__":
    base = Path(__file__).resolve().parent
    text_file = base / "relatorio_alteracoes_vendas_2026-03-25.txt"
    pdf_file = base / "relatorio_alteracoes_vendas_2026-03-25.pdf"
    write_pdf(text_file, pdf_file)
