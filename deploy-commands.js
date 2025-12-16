import 'dotenv/config';
import { REST } from '@discordjs/rest';
import { Routes } from 'discord-api-types/v10';

const token = process.env.DISCORD_TOKEN;
// support multiple env var names for the application/client id
const clientId = process.env.CLIENT_ID || process.env.DISCORD_ID || process.env.APPLICATION_ID || process.env.APP_ID;
const guildId = process.env.GUILD_ID;

if (!token || !clientId || !guildId) {
  console.error('DISCORD_TOKEN, CLIENT_ID and GUILD_ID must be set in .env');
  process.exit(1);
}

const commands = [
  {
    name: 'tanks',
    description: 'Record kills (or counts) for a player',
    options: [
      { name: 'player', type: 3, description: 'Player name', required: true },
      { name: 'count', type: 4, description: 'Number of kills to add', required: false }
    ]
  },
  {
    name: 'kills',
    description: 'Show top kills',
    options: [ { name: 'top', type: 4, description: 'How many to show', required: false } ]
  },
  {
    name: 'killreset',
    description: 'Reset a player kills (admin-only)',
    options: [ { name: 'player', type: 3, description: 'Player name', required: true } ]
  },
  {
    name: 'tank',
    description: 'Search tanks (autocomplete)',
    options: [ { name: 'query', type: 3, description: 'Partial tank name', required: true, autocomplete: true } ]
  }
  ,{
    name: 'tankfill',
    description: 'Choose a tank to push to the website (autocomplete)',
    options: [ { name: 'name', type: 3, description: 'Tank name', required: true, autocomplete: true } ]
  }
];

const rest = new REST({ version: '10' }).setToken(token);

(async () => {
  try {
    console.log('Registering guild commands...');
    await rest.put(
      Routes.applicationGuildCommands(clientId, guildId),
      { body: commands }
    );
    console.log('Commands registered.');
  } catch (err) {
    console.error('Failed to register commands:', err);
  }
})();
