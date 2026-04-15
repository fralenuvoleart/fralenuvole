#!/usr/bin/env python3
import json

with open('.roo/mcp.json', 'r') as f:
    data = json.load(f)
    print('JSON is valid')
    print(f'Found {len(data["mcpServers"])} MCP servers')
    if 'n8n-mcp' in data['mcpServers']:
        print('n8n-mcp server is present in configuration')
    else:
        print('n8n-mcp server NOT found in configuration')