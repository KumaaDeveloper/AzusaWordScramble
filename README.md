## General
AzusaWordScramble is a Pocketmine plug-in that works to provide word stacking questions and if players answer, they will get random money.

## Features
- The question will be broadcast and the player must correctly answer the word stacking question
- There are already 300+ words about minecraft, can be set in word.yml
- Custom message
- Custom prize
- Custom economy
- Added a sound when a question appears, answers the question, and no one answers the question.

## Command
Commands | Default | Permission
--- | --- | ---
`/wordscramble` | Op | azusawordscramble.command

## Configuration
```yaml
# Configuration for WordScramble plugin

scramble_interval: 60

# Message when the player successfully answers the wordscramble
scramble_completion_message: "§e{player} §fanswers §a\"{answer}\" §fand gets a prize of §6{money} §fMoney"

# Message when no one answers the wordscramble
no_answer_message: "§cNo one answered wordscramble, move on to the next wordscramble..."

# Delay when scramble is missed or answered
scramble_delay_solved: 5

# Rewards when players successfully answer wordscramble
prize_min: 1000
prize_max: 10000

# Economy you are using (Must use LibPiggyEconomy)
Economy:
  type: "bedrockeconomy" # Change this to your specific economy provider if needed (bedrockeconomy/economyapi)
```

## Depend
| Authors | Github | Lib |
|---------|--------|-----|
| Cooldogepm | [Cooldogepm](https://github.com/cooldogepm) | [BedrockEconomy](https://github.com/cooldogepm/BedrockEconomy) |
| Mathchat900 | [Mathchat900](https://github.com/mathchat900) | [EconomyAPI](https://github.com/mathchat900/EconomyAPI-PM5) |
