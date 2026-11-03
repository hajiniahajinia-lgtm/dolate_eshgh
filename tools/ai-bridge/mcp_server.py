"""Local MCP scaffold for Dolate Eshgh.

This first version is intentionally read-only and disconnected from the site,
database, filesystem, shell, and browser. It exposes only a health check and
an explicit capability report. Run over stdio; do not expose it to the network.
"""
from mcp.server.fastmcp import FastMCP

mcp = FastMCP("Dolate Eshgh Safe Bridge")


@mcp.tool()
def health_check() -> dict:
    """Check that the local MCP process is running; performs no external access."""
    return {
        "status": "ok",
        "mode": "local_scaffold",
        "site_connected": False,
        "database_connected": False,
        "browser_enabled": False,
        "write_operations_enabled": False,
    }


@mcp.tool()
def available_capabilities() -> dict:
    """Report enabled and disabled capabilities without exposing secrets."""
    return {
        "enabled": ["health_check", "available_capabilities"],
        "disabled": [
            "database_queries",
            "site_login",
            "browser_automation",
            "file_mutations",
            "database_writes",
            "arbitrary_sql",
            "shell_commands",
        ],
        "next_phase": "review security and add named read-only diagnostics",
    }


if __name__ == "__main__":
    mcp.run(transport="stdio")
