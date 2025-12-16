import fs from 'fs/promises';
import path from 'path';
import { Client, GatewayIntentBits, Partials } from 'discord.js';
import dotenv from 'dotenv';

dotenv.config();

const TOKEN = process.env.DISCORD_TOKEN;
const ADMIN_IDS = (process.env.DISCORD_ADMIN_IDS || '').split(',').map(s=>s.trim()).filter(Boolean);
if (!TOKEN) {
  console.error('DISCORD_TOKEN not set. Create a .env file with DISCORD_TOKEN=.');
  process.exit(1);
}

const DB_FILE = path.join(process.cwd(), 'db', 'discord_kills.json');
const TANKS_SUMMARY_FILE = path.join(process.cwd(), 'db', 'tanks_summary.json');

function normalizeName(s){
  if (!s) return '';
  return String(s).trim().toLowerCase();
}

async function loadKills(){
  try {
    const txt = await fs.readFile(DB_FILE, 'utf8');
    return JSON.parse(txt || '{}');
  } catch (e) {
    if (e.code === 'ENOENT') return {};
    console.error('Failed to load kills file:', e);
    return {};
  }
}

async function saveKills(obj){
  try {
    const tmp = DB_FILE + '.tmp';
    await fs.writeFile(tmp, JSON.stringify(obj, null, 2), 'utf8');
    await fs.rename(tmp, DB_FILE);
  } catch (e) {
    console.error('Failed to save kills file:', e);
  }
}

async function loadTanksSummary(){
  try {
    const txt = await fs.readFile(TANKS_SUMMARY_FILE, 'utf8');
    const data = JSON.parse(txt || '{}');
    const out = [];
    for (const group in data) {
      const arr = data[group];
      if (!Array.isArray(arr)) continue;
      for (const item of arr) {
        const name = item && (item.Name || item.name) ? (item.Name || item.name) : null;
        const amount = item && (item['Amount of Tanks Made'] !== undefined ? item['Amount of Tanks Made'] : (item.amount !== undefined ? item.amount : null));
        if (name) out.push({ name: String(name), group: group, amount: amount });
      }
    }
    return out;
  } catch (e) {
    return [];
  }
}

const client = new Client({
  intents: [GatewayIntentBits.Guilds],
  partials: [Partials.Channel]
});

client.once('ready', () => {
  console.log(`Discord bot ready as ${client.user.tag}`);
});

client.on('interactionCreate', async interaction => {
  try {
    if (interaction.isAutocomplete()) {
      if (interaction.commandName === 'tank') {
          const focused = interaction.options.getFocused();
          const q = String(focused || '').toLowerCase();
        const tanks = await loadTanksSummary();
        const choices = tanks
          .map(t => ({ name: t.name, value: t.name }))
          .filter(c => c.name.toLowerCase().includes(q))
          .slice(0, 25);
        await interaction.respond(choices);
      }
      return;
    }

    if (!interaction.isChatInputCommand()) return;

    const cmd = interaction.commandName;

    if (cmd === 'tanks') {
      const player = interaction.options.getString('player');
      const count = interaction.options.getInteger('count') || 1;
      if (!player) {
        await interaction.reply({ content: 'You must provide a player name.', ephemeral: true });
        return;
      }
      const displayName = player;
      const inc = Math.max(0, count);
      const kills = await loadKills();
      const key = normalizeName(displayName);
      if (!kills[key]) kills[key] = { displayName: displayName, count: 0, lastUpdated: null };
      kills[key].count = (kills[key].count || 0) + inc;
      kills[key].lastUpdated = Math.floor(Date.now()/1000);
      kills[key].lastBy = `${interaction.user.username}#${interaction.user.discriminator}`;
      await saveKills(kills);
      await interaction.reply({ content: `Recorded +${inc} for **${kills[key].displayName}** (total ${kills[key].count}).` });
      return;
    }

    if (cmd === 'kills') {
      const topN = interaction.options.getInteger('top') || 10;
      const kills = await loadKills();
      const list = Object.values(kills).sort((a,b)=> (b.count||0) - (a.count||0)).slice(0, topN);
      if (list.length === 0) {
        await interaction.reply({ content: 'No kill data recorded yet.', ephemeral: true });
        return;
      }
      const lines = list.map((r,i)=> `${i+1}. ${r.displayName} — ${r.count}`);
      const out = lines.join('\n');
      if (out.length < 1900) {
        await interaction.reply({ content: 'Top kills:\n' + out });
      } else {
        await interaction.reply({ content: 'Top kills (too long, sent as file):' });
        await interaction.followUp({ files: [{ attachment: Buffer.from(out, 'utf8'), name: 'kills.txt' }] });
      }
      return;
    }

    if (cmd === 'killreset') {
      const player = interaction.options.getString('player');
      if (!player) {
        await interaction.reply({ content: 'You must provide a player name to reset.', ephemeral: true });
        return;
      }
      const isAdmin = ADMIN_IDS.includes(String(interaction.user.id)) || (interaction.member && interaction.member.permissions && interaction.member.permissions.has && interaction.member.permissions.has('ManageGuild'));
      if (!isAdmin) {
        await interaction.reply({ content: 'You are not authorized to use this command.', ephemeral: true });
        return;
      }
      const kills = await loadKills();
      const key = normalizeName(player);
      if (!kills[key]) {
        await interaction.reply({ content: `No record for ${player}`, ephemeral: true });
        return;
      }
      delete kills[key];
      await saveKills(kills);
      await interaction.reply({ content: `Reset kills for ${player}.` });
      return;
    }

    if (cmd === 'tank') {
      const q = interaction.options.getString('query') || '';
      const tanks = await loadTanksSummary();
      const matches = tanks
        .map(t => ({ t, lname: t.name.toLowerCase() }))
        .filter(x => x.lname.includes(q.toLowerCase()))
        .sort((a,b)=>{
          const aStarts = a.lname.startsWith(q.toLowerCase()) ? 0 : 1;
          const bStarts = b.lname.startsWith(q.toLowerCase()) ? 0 : 1;
          if (aStarts !== bStarts) return aStarts - bStarts;
          return a.lname.localeCompare(b.lname);
        })
        .slice(0, 10)
        .map(x => x.t);
      if (matches.length === 0) {
        await interaction.reply({ content: 'No tanks matched your query.', ephemeral: true });
        return;
      }
      const lines = matches.map((m,i) => `${i+1}. ${m.name} (${m.group})` + (m.amount !== null && m.amount !== undefined ? ` — made: ${m.amount}` : ''));
      const out = lines.join('\n');
      await interaction.reply({ content: 'Tank suggestions:\n' + out });
      return;
    }

  } catch (err) {
    console.error('Interaction handler error:', err);
    try { if (interaction && !interaction.replied) await interaction.reply({ content: 'An error occurred.', ephemeral: true }); } catch(e){}
  }

});

client.login(TOKEN).catch(err=>{
  console.error('Failed to login:', err);
  process.exit(1);
});
