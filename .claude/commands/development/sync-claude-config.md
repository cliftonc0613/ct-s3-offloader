---
name: sync-claude-config
description: Sync agents, commands, and skills from a central Claude Code repository
color: purple
model: opus
---

# Sync Claude Configuration

Synchronizes `.claude` configurations from a central repository to your current project.

## What This Does

This command lets you:
- Pull the latest agents, commands, and skills from your central repository
- Choose what to sync (everything, by category, or specific items)
- Automatically create backups before overwriting
- Keep your projects synchronized with your standard toolset

## How to Use

Run this command when you want to:
1. **Start a new project** - Pull in all standard agents and commands
2. **Update agents** - Get the latest improvements to existing agents
3. **Add new tools** - Selectively pull specific new agents or commands
4. **Sync projects** - Keep multiple projects in sync

## Usage

```
/sync-claude-config
```

The command will:
1. Read your `.claude/config.json` to find the central repository
2. Ask what you want to sync
3. Clone the central repo
4. Create a backup of your current `.claude` directory
5. Sync selected items
6. Clean up and show results

## What Gets Synced

The skill can sync:
- **Agents** - AI agents for specialized tasks (research, design, content, etc.)
- **Commands** - Slash commands for common workflows
- **Skills** - Reusable skills with bundled resources

## Sync Modes

**Mode 1: Sync All**
- Pull everything from central repo
- Best for fresh projects

**Mode 2: Sync by Category**
- Choose agents, commands, or skills
- Select specific subcategories
- Good for targeted updates

**Mode 3: Sync Specific Items**
- Choose individual agents or commands
- Most precise control

## Configuration

Your project uses `.claude/config.json` to:
- Define the central repository URL
- List available agents/commands/skills
- Set sync preferences

Edit it to change the central repo source or customize what's available.

## Safety

- Backups are automatically created before syncing (timestamp: `.claude.backup.YYYYMMDD_HHMMSS`)
- All changes are safe to undo by restoring from backup
- You'll be shown what's being synced before it happens

## Example

After running `/sync-claude-config`:

```
1. Cloning central repository...
✓ Repository cloned successfully

2. What would you like to sync?
   [A] Everything
   [B] By category (choose agents/commands/skills)
   [C] Specific items only

>> B

3. Which categories?
   [✓] Agents
   [ ] Commands
   [ ] Skills

4. Creating backup at .claude.backup.20251214_143022...
✓ Backup created

5. Syncing agents...
  ✓ Synced research/search-specialist
  ✓ Synced design-development/premium-ui-designer
  ... (more items)

✓ Sync completed successfully
```

## Troubleshooting

**"Failed to clone repository"**
- Check internet connection
- Verify central repo URL in `.claude/config.json`
- Ensure you have git installed

**"Permission denied"**
- Check you have write access to the project directory
- Ensure `.claude` directory exists

**"Want to undo the sync?"**
- Restore from backup: `.claude.backup.YYYYMMDD_HHMMSS/`
- Copy the backup back to `.claude/`

## See Also

- `.claude/config.json` - Configuration file
- `.claude/skills/sync-claude-config/` - Skill definition and scripts
- `/help` - General Claude Code help
