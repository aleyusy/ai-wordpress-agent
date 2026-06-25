#!/usr/bin/env node

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

const WP_URL = process.env.WP_URL || "http://localhost";
const WP_USER = process.env.WP_USER || "admin";
const WP_APP_PASSWORD = process.env.WP_APP_PASSWORD || "";

async function aiwp(endpoint: string, method = "GET", body?: any): Promise<any> {
  const url = `${WP_URL}/wp-json/aiwp/v1/${endpoint}`;
  const headers: Record<string, string> = { "Content-Type": "application/json" };
  if (WP_APP_PASSWORD) {
    headers["Authorization"] = "Basic " + Buffer.from(`${WP_USER}:${WP_APP_PASSWORD}`).toString("base64");
  }
  const res = await fetch(url, { method, headers, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`API error ${res.status}: ${await res.text()}`);
  return res.json();
}

async function execTool(name: string, args: any = {}): Promise<any> {
  const result = await aiwp("execute", "POST", { name, args });
  return result;
}

const server = new McpServer({ name: "aiwp-wordpress", version: "1.0.0" });

// ========== CONTENT ==========

server.tool("wp_get_pages", "Get WordPress pages", {
  status: z.enum(["publish", "draft", "pending", "trash", "any"]).optional(),
  limit: z.number().optional(),
  search: z.string().optional(),
}, async (args) => {
  const r = await execTool("wp_get_pages", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_create_page", "Create a WordPress page", {
  title: z.string().describe("Page title"),
  content: z.string().optional().describe("HTML content"),
  status: z.enum(["publish", "draft", "pending"]).optional(),
  slug: z.string().optional(),
}, async (args) => {
  const r = await execTool("wp_create_page", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_update_page", "Update a WordPress page", {
  page_id: z.number().describe("Page ID"),
  title: z.string().optional(),
  content: z.string().optional(),
  status: z.enum(["publish", "draft", "pending", "trash"]).optional(),
}, async (args) => {
  const r = await execTool("wp_update_page", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_delete_page", "Delete a WordPress page", {
  page_id: z.number().describe("Page ID"),
  force: z.boolean().optional(),
}, async (args) => {
  const r = await execTool("wp_delete_page", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_get_posts", "Get WordPress posts", {
  status: z.enum(["publish", "draft", "pending", "trash", "any"]).optional(),
  limit: z.number().optional(),
  search: z.string().optional(),
}, async (args) => {
  const r = await execTool("wp_get_posts", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_create_post", "Create a WordPress post", {
  title: z.string().describe("Post title"),
  content: z.string().optional(),
  status: z.enum(["publish", "draft", "pending"]).optional(),
  categories: z.array(z.number()).optional(),
  tags: z.array(z.number()).optional(),
}, async (args) => {
  const r = await execTool("wp_create_post", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_update_post", "Update a WordPress post", {
  post_id: z.number().describe("Post ID"),
  title: z.string().optional(),
  content: z.string().optional(),
  status: z.enum(["publish", "draft", "pending", "trash"]).optional(),
}, async (args) => {
  const r = await execTool("wp_update_post", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_delete_post", "Delete a WordPress post", {
  post_id: z.number().describe("Post ID"),
  force: z.boolean().optional(),
}, async (args) => {
  const r = await execTool("wp_delete_post", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

// ========== TAXONOMY ==========

server.tool("wp_get_categories", "Get post categories", {}, async () => {
  const r = await execTool("wp_get_categories");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_add_category", "Create a category", {
  name: z.string().describe("Category name"),
  slug: z.string().optional(),
  description: z.string().optional(),
  parent_id: z.number().optional(),
}, async (args) => {
  const r = await execTool("wp_add_category", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_get_tags", "Get post tags", {}, async () => {
  const r = await execTool("wp_get_tags");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_add_tag", "Create a tag", {
  name: z.string().describe("Tag name"),
  slug: z.string().optional(),
}, async (args) => {
  const r = await execTool("wp_add_tag", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_get_post_types", "Get registered post types", {}, async () => {
  const r = await execTool("wp_get_post_types");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_get_taxonomies", "Get registered taxonomies", {}, async () => {
  const r = await execTool("wp_get_taxonomies");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

// ========== PLUGINS ==========

server.tool("wp_get_plugins", "Get installed plugins", {
  status: z.enum(["all", "active", "inactive"]).optional(),
}, async (args) => {
  const r = await execTool("wp_get_plugins", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_install_plugin", "Install a plugin from WordPress.org", {
  slug: z.string().describe("Plugin slug"),
  activate: z.boolean().optional(),
}, async (args) => {
  const r = await execTool("wp_install_plugin", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_activate_plugin", "Activate a plugin", {
  plugin: z.string().describe("Plugin slug or path"),
}, async (args) => {
  const r = await execTool("wp_activate_plugin", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_deactivate_plugin", "Deactivate a plugin", {
  plugin: z.string().describe("Plugin slug or path"),
}, async (args) => {
  const r = await execTool("wp_deactivate_plugin", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

// ========== THEMES ==========

server.tool("wp_get_themes", "Get installed themes", {}, async () => {
  const r = await execTool("wp_get_themes");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_switch_theme", "Switch active theme", {
  theme: z.string().describe("Theme slug"),
}, async (args) => {
  const r = await execTool("wp_switch_theme", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_get_theme_mods", "Get theme modifications", {}, async () => {
  const r = await execTool("wp_get_theme_mods");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_set_theme_mod", "Set a theme modification", {
  key: z.string().describe("Mod key"),
  value: z.string().describe("Mod value"),
}, async (args) => {
  const r = await execTool("wp_set_theme_mod", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_get_custom_css", "Get custom CSS", {}, async () => {
  const r = await execTool("wp_get_custom_css");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_add_custom_css", "Add custom CSS", {
  css: z.string().describe("CSS code"),
  append: z.boolean().optional(),
}, async (args) => {
  const r = await execTool("wp_add_custom_css", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

// ========== MENUS ==========

server.tool("wp_get_menus", "Get navigation menus", {}, async () => {
  const r = await execTool("wp_get_menus");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_create_menu", "Create a navigation menu", {
  name: z.string().describe("Menu name"),
  location: z.string().optional(),
}, async (args) => {
  const r = await execTool("wp_create_menu", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_add_menu_item", "Add item to a menu", {
  menu_id: z.number().describe("Menu ID"),
  title: z.string().describe("Item title"),
  url: z.string().optional(),
  type: z.enum(["custom", "page", "post", "category"]).optional(),
  object_id: z.number().optional(),
}, async (args) => {
  const r = await execTool("wp_add_menu_item", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

// ========== MEDIA ==========

server.tool("wp_get_media", "Get media library items", {
  limit: z.number().optional(),
  type: z.enum(["image", "video", "audio", "document"]).optional(),
}, async (args) => {
  const r = await execTool("wp_get_media", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_upload_media", "Upload media from URL", {
  url: z.string().describe("Media URL"),
  title: z.string().optional(),
  alt_text: z.string().optional(),
}, async (args) => {
  const r = await execTool("wp_upload_media", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

// ========== WIDGETS ==========

server.tool("wp_get_sidebars", "Get registered sidebars", {}, async () => {
  const r = await execTool("wp_get_sidebars");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_add_widget", "Add widget to sidebar", {
  sidebar_id: z.string().describe("Sidebar ID"),
  widget_type: z.string().describe("Widget type"),
  settings: z.record(z.string()).optional(),
}, async (args) => {
  const r = await execTool("wp_add_widget", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_remove_widget", "Remove widget from sidebar", {
  widget_id: z.string().describe("Widget ID"),
  sidebar_id: z.string().optional(),
}, async (args) => {
  const r = await execTool("wp_remove_widget", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

// ========== OPTIONS ==========

server.tool("wp_get_site_info", "Get WordPress site information", {}, async () => {
  const r = await execTool("wp_get_site_info");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_get_option", "Get a WordPress option", {
  key: z.string().describe("Option name"),
}, async (args) => {
  const r = await execTool("wp_get_option", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_update_option", "Update a WordPress option", {
  key: z.string().describe("Option name"),
  value: z.string().describe("Option value"),
}, async (args) => {
  const r = await execTool("wp_update_option", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_set_homepage", "Set homepage", {
  page_id: z.number().describe("Page ID"),
  posts_page_id: z.number().optional(),
}, async (args) => {
  const r = await execTool("wp_set_homepage", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

// ========== USERS ==========

server.tool("wp_get_users", "Get WordPress users", {
  role: z.string().optional(),
  limit: z.number().optional(),
}, async (args) => {
  const r = await execTool("wp_get_users", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("wp_get_comments", "Get comments", {
  status: z.enum(["hold", "approve", "spam", "trash"]).optional(),
  limit: z.number().optional(),
  post_id: z.number().optional(),
}, async (args) => {
  const r = await execTool("wp_get_comments", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

// ========== AIWP TOOLS ==========

server.tool("aiwp_analyze_site", "Run site analysis", {}, async () => {
  const r = await execTool("aiwp_analyze_site");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_get_site_analysis", "Get site analysis results", {}, async () => {
  const r = await execTool("aiwp_get_site_analysis");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_save_memory", "Save to agent memory", {
  category: z.string().describe("Memory category"),
  key: z.string().describe("Memory key"),
  value: z.any().describe("Value to store"),
}, async (args) => {
  const r = await execTool("aiwp_save_memory", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_search_memory", "Search agent memory", {
  query: z.string().describe("Search query"),
}, async (args) => {
  const r = await execTool("aiwp_search_memory", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_list_skills", "List available skills", {}, async () => {
  const r = await execTool("aiwp_list_skills");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_save_skill", "Save a skill", {
  slug: z.string().describe("Skill slug"),
  skill_data: z.string().describe("Skill data as JSON string"),
}, async (args) => {
  const r = await execTool("aiwp_save_skill", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_search_plugins", "Search plugins on WordPress.org", {
  query: z.string().describe("Search query"),
}, async (args) => {
  const r = await execTool("aiwp_search_plugins", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_get_plugin_docs", "Get plugin documentation", {
  slug: z.string().describe("Plugin slug"),
}, async (args) => {
  const r = await execTool("aiwp_get_plugin_docs", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_list_roles", "List roles and capabilities", {}, async () => {
  const r = await execTool("aiwp_list_roles");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_list_theme_files", "List theme files", {}, async () => {
  const r = await execTool("aiwp_list_theme_files");
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_read_theme_file", "Read a theme file", {
  file_path: z.string().describe("File path"),
}, async (args) => {
  const r = await execTool("aiwp_read_theme_file", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

server.tool("aiwp_write_theme_file", "Write to a theme file", {
  file_path: z.string().describe("File path"),
  content: z.string().describe("File content"),
}, async (args) => {
  const r = await execTool("aiwp_write_theme_file", args);
  return { content: [{ type: "text" as const, text: JSON.stringify(r, null, 2) }] };
});

// ========== START ==========

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("AIWP MCP Server running");
}

main().catch((error) => {
  console.error("Fatal:", error);
  process.exit(1);
});
